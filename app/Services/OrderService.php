<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Events\OrderCancelled;
use App\Events\OrderConfirmed;
use App\Events\OrderStatusUpdated;
use App\Events\PaymentSucceeded;
use App\Events\RefundProcessed;
use App\Exceptions\DomainException;
use App\Models\ActivityLog;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Staff;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OrderService
{
    public function __construct(
        private readonly InventoryService $inventoryService,
        private readonly StripeGatewayInterface $stripeGateway,
    ) {}

    public function updateStatus(Order $order, OrderStatus $newStatus, Staff $actor, ?string $reason = null): Order
    {
        if ($newStatus === OrderStatus::Cancelled) {
            return $this->cancel($order, $actor, $reason);
        }

        $previousStatus = $order->status;

        /** @var Order $updated */
        $updated = DB::transaction(function () use ($order, $newStatus, $actor, &$previousStatus): Order {
            /** @var Order $locked */
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $previousStatus = $locked->status;

            if ($locked->status === $newStatus) {
                return $locked;
            }

            if (! $locked->status->canTransitionTo($newStatus)) {
                throw new DomainException(
                    "Invalid status transition from '{$locked->status->value}' to '{$newStatus->value}'.",
                    409
                );
            }

            if ($newStatus === OrderStatus::Paid && $locked->payment_status !== PaymentStatus::Succeeded) {
                throw new DomainException(
                    'Orders can only transition to paid after server-verified Stripe payment.',
                    409
                );
            }

            $locked->update(['status' => $newStatus]);

            ActivityLog::query()->create([
                'actor_id' => $actor->id,
                'action' => 'order.status_updated',
                'subject_type' => Order::class,
                'subject_id' => $locked->id,
                'properties' => [
                    'from' => $previousStatus->value,
                    'to' => $newStatus->value,
                ],
            ]);

            return $locked->fresh(['items', 'payments', 'user']);
        });

        if ($previousStatus !== $newStatus) {
            event(new OrderStatusUpdated($updated, $previousStatus));
        }

        return $updated;
    }

    /**
     * Update an order's payment status with transactional stock safety,
     * payment record syncing, and activity audit logging.
     */
    public function updatePaymentStatus(
        Order $order,
        PaymentStatus $newPaymentStatus,
        Staff $actor,
        ?string $note = null,
    ): Order {
        $previousOrderStatus = $order->status;
        $previousPaymentStatus = $order->payment_status;
        $syncedPayment = null;

        /** @var Order $updated */
        $updated = DB::transaction(function () use (
            $order,
            $newPaymentStatus,
            $actor,
            $note,
            &$previousOrderStatus,
            &$previousPaymentStatus,
            &$syncedPayment
        ): Order {
            /** @var Order $locked */
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $previousOrderStatus = $locked->status;
            $previousPaymentStatus = $locked->payment_status;

            if ($locked->payment_status === $newPaymentStatus) {
                return $locked->fresh(['items', 'payments', 'user']);
            }

            // Prevent nonsensical payment rollbacks on delivered orders
            if (
                $locked->status === OrderStatus::Delivered &&
                in_array($newPaymentStatus, [PaymentStatus::Pending, PaymentStatus::Failed, PaymentStatus::Cancelled], true)
            ) {
                throw new DomainException(
                    "Cannot change payment status of a delivered order to '{$newPaymentStatus->value}'.",
                    409
                );
            }

            // Refunded requires a prior succeeded or needs_resolution payment
            if (
                $newPaymentStatus === PaymentStatus::Refunded &&
                ! in_array($locked->payment_status, [PaymentStatus::Succeeded, PaymentStatus::NeedsResolution], true)
            ) {
                throw new DomainException(
                    'Only paid orders can be marked as refunded.',
                    409
                );
            }

            // Cancelled orders cannot transition back to succeeded payment
            if ($locked->status === OrderStatus::Cancelled && $newPaymentStatus === PaymentStatus::Succeeded) {
                throw new DomainException(
                    'Cancelled orders cannot be marked as paid.',
                    409
                );
            }

            /** @var Payment|null $latestPayment */
            $latestPayment = $locked->payments()->latest('id')->first();

            if ($newPaymentStatus === PaymentStatus::Succeeded) {
                // Atomically validate and deduct stock with row locks (idempotent if already deducted)
                $this->inventoryService->deductForOrder($locked);

                $updateFields = [
                    'payment_status' => PaymentStatus::Succeeded,
                    'paid_at' => $locked->paid_at ?? now(),
                ];

                if ($locked->status === OrderStatus::Pending) {
                    $updateFields['status'] = OrderStatus::Paid;
                }

                $locked->update($updateFields);

                if ($latestPayment !== null) {
                    $latestPayment->update([
                        'status' => PaymentStatus::Succeeded,
                        'paid_at' => $latestPayment->paid_at ?? now(),
                        'failure_code' => null,
                        'failure_message' => null,
                    ]);
                    $syncedPayment = $latestPayment->fresh();
                } else {
                    $syncedPayment = Payment::query()->create([
                        'order_id' => $locked->id,
                        'provider' => 'manual',
                        'status' => PaymentStatus::Succeeded,
                        'amount' => $locked->total_amount,
                        'currency' => $locked->currency,
                        'paid_at' => now(),
                    ]);
                }
            } elseif ($newPaymentStatus === PaymentStatus::Refunded) {
                $locked->update(['payment_status' => PaymentStatus::Refunded]);

                if ($latestPayment !== null) {
                    $latestPayment->update([
                        'status' => PaymentStatus::Refunded,
                        'refunded_at' => $latestPayment->refunded_at ?? now(),
                    ]);
                    $syncedPayment = $latestPayment->fresh();
                }
            } else {
                $locked->update(['payment_status' => $newPaymentStatus]);

                if ($latestPayment !== null) {
                    $paymentUpdate = ['status' => $newPaymentStatus];
                    if ($newPaymentStatus === PaymentStatus::Failed && $note) {
                        $paymentUpdate['failure_message'] = $note;
                    }
                    $latestPayment->update($paymentUpdate);
                    $syncedPayment = $latestPayment->fresh();
                }
            }

            ActivityLog::query()->create([
                'actor_id' => $actor->id,
                'action' => 'order.payment_status_updated',
                'subject_type' => Order::class,
                'subject_id' => $locked->id,
                'properties' => [
                    'from' => $previousPaymentStatus->value,
                    'to' => $newPaymentStatus->value,
                    'order_status_from' => $previousOrderStatus->value,
                    'order_status_to' => $locked->status->value,
                    'note' => $note,
                ],
            ]);

            return $locked->fresh(['items', 'payments', 'user']);
        });

        if ($previousPaymentStatus !== $newPaymentStatus) {
            if ($newPaymentStatus === PaymentStatus::Succeeded && $syncedPayment instanceof Payment) {
                event(new PaymentSucceeded($updated, $syncedPayment));
                if ($previousOrderStatus === OrderStatus::Pending && $updated->status === OrderStatus::Paid) {
                    event(new OrderConfirmed($updated));
                }
            } elseif ($newPaymentStatus === PaymentStatus::Refunded && $syncedPayment instanceof Payment) {
                event(new RefundProcessed($updated, $syncedPayment));
            } elseif ($previousOrderStatus !== $updated->status) {
                event(new OrderStatusUpdated($updated, $previousOrderStatus));
            }
        }

        return $updated;
    }

    /**
     * Cancel an order, restore stock strictly once (if paid & deducted),
     * and process Stripe refund when a verified payment exists.
     */
    public function cancel(Order $order, Staff $actor, ?string $reason = null): Order
    {
        $paymentToRefund = null;

        /** @var Order $cancelled */
        $cancelled = DB::transaction(function () use ($order, $actor, $reason, &$paymentToRefund): Order {
            /** @var Order $locked */
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === OrderStatus::Cancelled) {
                throw new DomainException('This order is already cancelled.', 409);
            }

            if (! $locked->status->isCancellable()) {
                throw new DomainException(
                    "Orders with status '{$locked->status->value}' cannot be cancelled.",
                    409
                );
            }

            $stockRestored = $this->inventoryService->restoreForOrder($locked, $actor->id);

            $locked->update([
                'status' => OrderStatus::Cancelled,
                'cancelled_at' => now(),
                'cancellation_reason' => $reason ?: 'Cancelled by store administrator',
            ]);

            /** @var Payment|null $succeededPayment */
            $succeededPayment = $locked->payments()
                ->where('status', PaymentStatus::Succeeded)
                ->whereNotNull('stripe_payment_intent_id')
                ->whereNull('stripe_refund_id')
                ->first();

            if ($succeededPayment !== null) {
                $paymentToRefund = $succeededPayment;
            }

            ActivityLog::query()->create([
                'actor_id' => $actor->id,
                'action' => 'order.cancelled',
                'subject_type' => Order::class,
                'subject_id' => $locked->id,
                'properties' => [
                    'reason' => $locked->cancellation_reason,
                    'stock_restored' => $stockRestored,
                ],
            ]);

            return $locked->fresh(['items', 'payments', 'user']);
        });

        event(new OrderCancelled($cancelled));

        if ($paymentToRefund instanceof Payment && $paymentToRefund->stripe_payment_intent_id !== null) {
            try {
                $refund = $this->stripeGateway->createRefund(
                    paymentIntentId: $paymentToRefund->stripe_payment_intent_id,
                    reason: 'requested_by_customer',
                    idempotencyKey: "cancel-refund-order-{$cancelled->id}-payment-{$paymentToRefund->id}",
                );

                $paymentToRefund->update([
                    'status' => PaymentStatus::Refunded,
                    'stripe_refund_id' => $refund['id'],
                    'refunded_at' => now(),
                ]);

                $cancelled->update(['payment_status' => PaymentStatus::Refunded]);

                event(new RefundProcessed($cancelled->fresh(['items']), $paymentToRefund->fresh()));
            } catch (\Throwable $e) {
                Log::warning('Stripe refund could not be processed automatically during order cancellation.', [
                    'order_id' => $cancelled->id,
                    'payment_id' => $paymentToRefund->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $cancelled->fresh(['items', 'payments', 'user']);
    }
}
