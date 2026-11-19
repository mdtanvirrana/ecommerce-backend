<?php

namespace App\Services;

use App\Exceptions\DomainException;
use Stripe\Exception\SignatureVerificationException;
use Stripe\StripeClient;
use Stripe\Webhook;
use UnexpectedValueException;

class StripeGateway implements StripeGatewayInterface
{
    private ?StripeClient $client = null;

    private function client(): StripeClient
    {
        if ($this->client === null) {
            $secret = (string) config('services.stripe.secret');
            if ($secret === '' || str_starts_with($secret, 'sk_test_your_')) {
                throw new DomainException('Stripe Test Mode secret key is not configured on the server.', 503);
            }
            $this->client = new StripeClient($secret);
        }

        return $this->client;
    }

    public function createPaymentIntent(int $amountInCents, string $currency, array $metadata, ?string $idempotencyKey = null): array
    {
        $options = $idempotencyKey !== null ? ['idempotency_key' => $idempotencyKey] : [];

        $intent = $this->client()->paymentIntents->create([
            'amount' => $amountInCents,
            'currency' => strtolower($currency),
            'payment_method_types' => ['card'],
            'metadata' => $metadata,
        ], $options);

        return [
            'id' => (string) $intent->id,
            'client_secret' => (string) $intent->client_secret,
            'status' => (string) $intent->status,
            'amount' => (int) $intent->amount,
            'currency' => strtolower((string) $intent->currency),
        ];
    }

    public function retrievePaymentIntent(string $paymentIntentId): array
    {
        $intent = $this->client()->paymentIntents->retrieve($paymentIntentId, []);

        $lastError = null;
        if ($intent->last_payment_error !== null) {
            $lastError = [
                'code' => $intent->last_payment_error->code ?? null,
                'message' => $intent->last_payment_error->message ?? null,
            ];
        }

        return [
            'id' => (string) $intent->id,
            'status' => (string) $intent->status,
            'amount' => (int) $intent->amount,
            'amount_received' => (int) ($intent->amount_received ?? $intent->amount),
            'currency' => strtolower((string) $intent->currency),
            'latest_charge' => is_string($intent->latest_charge) ? $intent->latest_charge : ($intent->latest_charge->id ?? null),
            'metadata' => method_exists($intent->metadata, 'toArray') ? $intent->metadata->toArray() : (array) $intent->metadata,
            'last_payment_error' => $lastError,
        ];
    }

    public function createRefund(string $paymentIntentId, ?string $reason = null, ?string $idempotencyKey = null): array
    {
        $params = ['payment_intent' => $paymentIntentId];
        if (in_array($reason, ['duplicate', 'fraudulent', 'requested_by_customer'], true)) {
            $params['reason'] = $reason;
        }

        $options = $idempotencyKey !== null ? ['idempotency_key' => $idempotencyKey] : [];
        $refund = $this->client()->refunds->create($params, $options);

        return [
            'id' => (string) $refund->id,
            'status' => (string) $refund->status,
            'amount' => (int) $refund->amount,
        ];
    }

    public function constructWebhookEvent(string $payload, string $signatureHeader, string $webhookSecret): array
    {
        if ($webhookSecret === '') {
            throw new DomainException('Stripe webhook secret is not configured.', 503);
        }

        try {
            $event = Webhook::constructEvent($payload, $signatureHeader, $webhookSecret);
        } catch (UnexpectedValueException|SignatureVerificationException $e) {
            throw new DomainException('Invalid Stripe webhook signature.', 400);
        }

        return [
            'id' => (string) $event->id,
            'type' => (string) $event->type,
            'data' => $event->data->toArray(),
        ];
    }
}
