<?php

namespace Tests\Support;

use App\Exceptions\DomainException;
use App\Services\StripeGatewayInterface;

class FakeStripeGateway implements StripeGatewayInterface
{
    /** @var array<string, array<string, mixed>> */
    public array $intents = [];

    /** @var list<array<string, mixed>> */
    public array $refunds = [];

    public bool $failNextRefund = false;

    public string $validSignature = 't=123,v1=valid_test_signature';

    private int $counter = 1;

    public function createPaymentIntent(int $amountInCents, string $currency, array $metadata, ?string $idempotencyKey = null): array
    {
        $id = 'pi_test_'.$this->counter++;
        $secret = "{$id}_secret_fake";

        $this->intents[$id] = [
            'id' => $id,
            'client_secret' => $secret,
            'status' => 'requires_payment_method',
            'amount' => $amountInCents,
            'amount_received' => 0,
            'currency' => strtolower($currency),
            'latest_charge' => null,
            'metadata' => $metadata,
            'last_payment_error' => null,
        ];

        return [
            'id' => $id,
            'client_secret' => $secret,
            'status' => 'requires_payment_method',
            'amount' => $amountInCents,
            'currency' => strtolower($currency),
        ];
    }

    public function markIntentSucceeded(string $paymentIntentId, ?int $overrideAmountReceived = null): void
    {
        $intent = $this->intents[$paymentIntentId];
        $intent['status'] = 'succeeded';
        $intent['amount_received'] = $overrideAmountReceived ?? $intent['amount'];
        $intent['latest_charge'] = 'ch_test_'.substr($paymentIntentId, 8);
        $this->intents[$paymentIntentId] = $intent;
    }

    public function markIntentFailed(string $paymentIntentId, string $code = 'card_declined', string $message = 'Your card was declined.'): void
    {
        $intent = $this->intents[$paymentIntentId];
        $intent['status'] = 'requires_payment_method';
        $intent['amount_received'] = 0;
        $intent['last_payment_error'] = ['code' => $code, 'message' => $message];
        $this->intents[$paymentIntentId] = $intent;
    }

    public function retrievePaymentIntent(string $paymentIntentId): array
    {
        if (! isset($this->intents[$paymentIntentId])) {
            throw new DomainException("Unknown fake PaymentIntent {$paymentIntentId}", 404);
        }

        return $this->intents[$paymentIntentId];
    }

    public function createRefund(string $paymentIntentId, ?string $reason = null, ?string $idempotencyKey = null): array
    {
        if ($this->failNextRefund) {
            $this->failNextRefund = false;
            throw new \RuntimeException('Simulated Stripe refund failure.');
        }

        $refund = [
            'id' => 're_test_'.(count($this->refunds) + 1),
            'status' => 'succeeded',
            'amount' => $this->intents[$paymentIntentId]['amount'] ?? 0,
            'payment_intent' => $paymentIntentId,
            'reason' => $reason,
        ];
        $this->refunds[] = $refund;

        return [
            'id' => $refund['id'],
            'status' => $refund['status'],
            'amount' => $refund['amount'],
        ];
    }

    public function constructWebhookEvent(string $payload, string $signatureHeader, string $webhookSecret): array
    {
        if ($signatureHeader !== $this->validSignature) {
            throw new DomainException('Invalid Stripe webhook signature.', 400);
        }

        $decoded = json_decode($payload, true);
        if (! is_array($decoded) || empty($decoded['id']) || empty($decoded['type'])) {
            throw new DomainException('Malformed webhook payload.', 400);
        }

        return [
            'id' => (string) $decoded['id'],
            'type' => (string) $decoded['type'],
            'data' => is_array($decoded['data'] ?? null) ? $decoded['data'] : [],
        ];
    }
}
