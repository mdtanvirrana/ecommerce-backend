<?php

namespace App\Services;

interface StripeGatewayInterface
{
    /**
     * @param  array<string, string>  $metadata
     * @return array{id: string, client_secret: string, status: string, amount: int, currency: string}
     */
    public function createPaymentIntent(int $amountInCents, string $currency, array $metadata, ?string $idempotencyKey = null): array;

    /**
     * @return array{id: string, status: string, amount: int, amount_received: int, currency: string, latest_charge: ?string, metadata: array<string, mixed>, last_payment_error: ?array{code: ?string, message: ?string}}
     */
    public function retrievePaymentIntent(string $paymentIntentId): array;

    /**
     * @return array{id: string, status: string, amount: int}
     */
    public function createRefund(string $paymentIntentId, ?string $reason = null, ?string $idempotencyKey = null): array;

    /**
     * Verify Stripe webhook signature and return normalized event payload.
     *
     * @return array{id: string, type: string, data: array<string, mixed>}
     */
    public function constructWebhookEvent(string $payload, string $signatureHeader, string $webhookSecret): array;
}
