<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Events\OrderCancelled;
use App\Events\OrderConfirmed;
use App\Events\PaymentSucceeded;
use App\Events\RefundProcessed;
use App\Exceptions\DomainException;
use App\Models\Cart;
use App\Models\Coupon;
use App\Models\CouponUsage;
use App\Models\Order;
use App\Models\Payment;
use App\Models\WebhookEvent;
use App\Support\Money;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PaymentService
{
    public function __construct(
        private readonly StripeGatewayInterface $stripeGateway,
        private readonly InventoryService $inventoryService,
    ) {}

    /**
     * Process an incoming Stripe webhook with signature verification and idempotency protection.
     *
     * @return array{status: string, event_id: string, duplicate?: bool}
     */
    public function handleWebhook(string $payload, string $signatureHeader): array
    {
        $webhookSecret = (string) config('services.stripe.webhook_secret');
        $event = $this->stripeGateway->constructWebhookEvent($payload, $signatureHeader, $webhookSecret);

        $eventId = $event['id'];
        $eventType = $event['type'];

        // Idempotency guard via unique event_id constraint.
        try {
            $record = WebhookEvent::query()->create([
                'provider' => 'stripe',
                'event_id' => $eventId,
                'event_type' => $eventType,
                'status' => 'processing',
                'payload' => $event,
            ]);
        } catch (QueryException) {
            return [
                'status' => 'already_processed',
                'event_id' => $eventId,
                'duplicate' => true,
            ];
        }

        try {
            $object = $event['data']['object'] ?? [];
            $paymentIntentId = is_array($object) ? (string) ($object['id'] ?? '') : '';

            match ($eventType) {
                'payment_intent.succeeded' => $paymentIntentId !== '' ? $this->confirmPaymentIntent($paymentIntentId) : null,
                'payment_intent.payment_failed' => $paymentIntentId !== '' ? $this->markPaymentFailed(
                    $paymentIntentId,
                    is_array($object['last_payment_error'] ?? null) ? ($object['last_payment_error']['code'] ?? null) : null,
                    is_array($object['last_payment_error'] ?? null) ? ($object['last_payment_error']['message'] ?? 'Payment failed') : 'Payment failed',
                ) : null,
                'payment_intent.canceled' => $paymentIntentId !== '' ? $this->markPaymentCancelled($paymentIntentId) : null,
                default => $record->update(['status' => 'ignored', 'processed_at' => now()]),
            };

            if ($record->status === 'processing') {
                $record->update(['status' => 'processed', 'processed_at' => now()]);
            }

            return [
                'status' => $record->status,
                'event_id' => $eventId,
            ];
        } catch (\Throwable $e) {
            $record->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Server-side payment reconciliation for delayed webhooks.
     * NEVER trusts frontend confirmation; always retrieves status directly from Stripe API.
     */
    public function reconcileOrderPayment(Order $order): Order
    {
        if ($order->status !== OrderStatus::Pending && $order->payment_status === PaymentStatus::Succeeded) {
            return $order->fresh(['items', 'latestPayment']);
        }

        /** @var Payment|null $payment */
        $payment = $order->payments()->whereNotNull('stripe_payment_intent_id')->first();

        if ($payment === null || $payment->stripe_payment_intent_id === null) {
            throw new DomainException('No Stripe payment intent found for this order.', 404);
        }

        $remoteIntent = $this->stripeGateway->retrievePaymentIntent($payment->stripe_payment_intent_id);

        return match ($remoteIntent['status']) {
            'succeeded' => $this->confirmPaymentIntent($payment->stripe_payment_intent_id),
            'canceled' => $this->markPaymentCancelled($payment->stripe_payment_intent_id) ?? $order->fresh(['items', 'latestPayment']),
            'requires_payment_method' => $remoteIntent['last_payment_error'] !== null
                ? ($this->markPaymentFailed(
                    $payment->stripe_payment_intent_id,
                    $remoteIntent['last_payment_error']['code'] ?? null,
                    $remoteIntent['last_payment_error']['message'] ?? 'Payment failed',
                ) ?? $order->fresh(['items', 'latestPayment']))
                : $order->fresh(['items', 'latestPayment']),
            default => $order->fresh(['items', 'latestPayment']),
        };
    }

    /**
     * Authoritatively verify a PaymentIntent against Stripe API, then atomically lock product rows,
     * validate stock, decrement inventory, record coupon usage, confirm order, and clear cart.
     */
    public function confirmPaymentIntent(string $paymentIntentId): Order
    {
        $remoteIntent = $this->stripeGateway->retrievePaymentIntent($paymentIntentId);

        if ($remoteIntent['status'] !== 'succeeded') {
            throw new DomainException("PaymentIntent {$paymentIntentId} is not succeeded (status: {$remoteIntent['status']}).", 409);
        }

        /** @var Payment|null $paymentRecord */
        $paymentRecord = Payment::query()
            ->where('stripe_payment_intent_id', $paymentIntentId)
            ->first();

        if ($paymentRecord === null) {
            throw new DomainException("Payment record not found for PaymentIntent {$paymentIntentId}.", 404);
        }

        $expectedCents = Money::toCents($paymentRecord->amount);
        $receivedCents = (int) ($remoteIntent['amount_received'] ?: $remoteIntent['amount']);

        if ($receivedCents !== $expectedCents) {
            throw new DomainException(
                "Payment amount mismatch for {$paymentIntentId} (expected {$expectedCents} cents, received {$receivedCents} cents).",
                409
            );
        }

        if (strtolower($remoteIntent['currency']) !== strtolower($paymentRecord->currency)) {
            throw new DomainException('Payment currency mismatch.', 409);
        }

        $eventsToDispatch = [];
        $oversellOccurred = false;
        $oversellReason = null;

        /** @var Order $confirmedOrder */
        $confirmedOrder = DB::transaction(function () use (
            $paymentRecord,
            $remoteIntent,
            &$eventsToDispatch,
            &$oversellOccurred,
            &$oversellReason
        ): Order {
            /** @var Order $order */
            $order = Order::query()
                ->whereKey($paymentRecord->order_id)
                ->lockForUpdate()
                ->firstOrFail();

            /** @var Payment $payment */
            $payment = Payment::query()
                ->whereKey($paymentRecord->id)
                ->lockForUpdate()
                ->firstOrFail();

            // Idempotent guard: already verified and confirmed.
            if ($order->stock_deducted_at !== null || $order->payment_status === PaymentStatus::Succeeded) {
                return $order;
            }

            if ($order->status === OrderStatus::Cancelled) {
                // Payment arrived after order cancellation — trigger safe oversell/refund handling below.
                $oversellOccurred = true;
                $oversellReason = 'Order was cancelled before payment verification completed.';

                $payment->update([
                    'status' => PaymentStatus::Succeeded,
                    'stripe_charge_id' => $remoteIntent['latest_charge'],
                    'verified_at' => now(),
                ]);

                return $order;
            }

            try {
                $this->inventoryService->deductForOrder($order);
            } catch (DomainException $e) {
                // Payment succeeded on Stripe, but stock became unavailable before fulfillment.
                // Maintain accurate payment record and initiate safe refund/manual-resolution outside lock.
                $oversellOccurred = true;
                $oversellReason = $e->getMessage();

                $payment->update([
                    'status' => PaymentStatus::Succeeded,
                    'stripe_charge_id' => $remoteIntent['latest_charge'],
                    'verified_at' => now(),
                    'metadata' => array_merge($payment->metadata ?? [], ['oversell_error' => $e->getMessage()]),
                ]);

                $order->update([
                    'status' => OrderStatus::Cancelled,
                    'payment_status' => PaymentStatus::NeedsResolution,
                    'cancelled_at' => now(),
                    'cancellation_reason' => "Inventory unavailable after payment: {$e->getMessage()}",
                ]);

                return $order;
            }

            $payment->update([
                'status' => PaymentStatus::Succeeded,
                'stripe_charge_id' => $remoteIntent['latest_charge'],
                'verified_at' => now(),
                'failure_code' => null,
                'failure_message' => null,
            ]);

            $order->update([
                'status' => OrderStatus::Paid,
                'payment_status' => PaymentStatus::Succeeded,
                'paid_at' => now(),
            ]);

            // Record coupon usage atomically once per order.
            if ($order->coupon_id !== null) {
                $usage = CouponUsage::query()->firstOrCreate(
                    [
                        'coupon_id' => $order->coupon_id,
                        'order_id' => $order->id,
                    ],
                    [
                        'user_id' => $order->user_id,
                        'discount_amount' => $order->discount_amount,
                    ]
                );

                if ($usage->wasRecentlyCreated) {
                    Coupon::query()->whereKey($order->coupon_id)->increment('used_count');
                }
            }

            // Clear customer's persistent shopping cart after verified payment.
            $cart = Cart::query()->where('user_id', $order->user_id)->first();
            if ($cart !== null) {
                $cart->items()->delete();
                $cart->update(['coupon_id' => null]);
            }

            $eventsToDispatch[] = new OrderConfirmed($order->fresh(['items']));
            $eventsToDispatch[] = new PaymentSucceeded($order->fresh(['items']), $payment->fresh());

            return $order;
        });

        // If payment succeeded but inventory was unavailable, attempt automatic Stripe refund cleanly.
        if ($oversellOccurred) {
            $payment = $paymentRecord->fresh();
            $this->attemptOversellRefund($confirmedOrder, $payment, (string) $oversellReason);
        }

        foreach ($eventsToDispatch as $event) {
            event($event);
        }

        return $confirmedOrder->fresh(['items', 'latestPayment']);
    }

    public function markPaymentFailed(string $paymentIntentId, ?string $failureCode, ?string $failureMessage): ?Order
    {
        return DB::transaction(function () use ($paymentIntentId, $failureCode, $failureMessage): ?Order {
            /** @var Payment|null $payment */
            $payment = Payment::query()
                ->where('stripe_payment_intent_id', $paymentIntentId)
                ->lockForUpdate()
                ->first();

            if ($payment === null) {
                return null;
            }

            /** @var Order $order */
            $order = Order::query()->whereKey($payment->order_id)->lockForUpdate()->firstOrFail();

            // Never downgrade an already verified paid order.
            if ($order->payment_status === PaymentStatus::Succeeded || $order->stock_deducted_at !== null) {
                return $order;
            }

            $payment->update([
                'status' => PaymentStatus::Failed,
                'failure_code' => $failureCode,
                'failure_message' => $failureMessage,
            ]);

            $order->update([
                'status' => OrderStatus::Pending,
                'payment_status' => PaymentStatus::Failed,
            ]);

            return $order->fresh(['items', 'latestPayment']);
        });
    }

    public function markPaymentCancelled(string $paymentIntentId): ?Order
    {
        return DB::transaction(function () use ($paymentIntentId): ?Order {
            /** @var Payment|null $payment */
            $payment = Payment::query()
                ->where('stripe_payment_intent_id', $paymentIntentId)
                ->lockForUpdate()
                ->first();

            if ($payment === null) {
                return null;
            }

            /** @var Order $order */
            $order = Order::query()->whereKey($payment->order_id)->lockForUpdate()->firstOrFail();

            if ($order->payment_status === PaymentStatus::Succeeded || $order->stock_deducted_at !== null) {
                return $order;
            }

            $payment->update(['status' => PaymentStatus::Cancelled]);
            $order->update(['payment_status' => PaymentStatus::Cancelled]);

            return $order->fresh(['items', 'latestPayment']);
        });
    }

    private function attemptOversellRefund(Order $order, Payment $payment, string $reason): void
    {
        if ($payment->stripe_payment_intent_id === null || $payment->stripe_refund_id !== null) {
            return;
        }

        try {
            $refund = $this->stripeGateway->createRefund(
                paymentIntentId: $payment->stripe_payment_intent_id,
                reason: 'requested_by_customer',
                idempotencyKey: "oversell-refund-order-{$order->id}-payment-{$payment->id}",
            );

            $payment->update([
                'status' => PaymentStatus::Refunded,
                'stripe_refund_id' => $refund['id'],
                'refunded_at' => now(),
            ]);

            $order->update([
                'status' => OrderStatus::Cancelled,
                'payment_status' => PaymentStatus::Refunded,
                'cancelled_at' => $order->cancelled_at ?? now(),
                'cancellation_reason' => "Automatically refunded due to stock unavailability: {$reason}",
            ]);

            event(new OrderCancelled($order->fresh(['items'])));
            event(new RefundProcessed($order->fresh(['items']), $payment->fresh()));
        } catch (\Throwable $e) {
            Log::error('Oversell automatic Stripe refund failed — manual resolution required.', [
                'order_id' => $order->id,
                'payment_id' => $payment->id,
                'error' => $e->getMessage(),
            ]);

            $order->update([
                'status' => OrderStatus::Cancelled,
                'payment_status' => PaymentStatus::NeedsResolution,
                'cancelled_at' => $order->cancelled_at ?? now(),
                'cancellation_reason' => "Manual refund required (oversell): {$reason}",
            ]);
        }
    }
}
