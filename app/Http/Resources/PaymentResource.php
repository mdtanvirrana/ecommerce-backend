<?php

namespace App\Http\Resources;

use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Payment */
class PaymentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'provider' => $this->provider,
            'stripe_payment_intent_id' => $this->stripe_payment_intent_id,
            'stripe_charge_id' => $this->stripe_charge_id,
            'stripe_refund_id' => $this->stripe_refund_id,
            'amount' => (string) $this->amount,
            'currency' => $this->currency,
            'status' => $this->status->value,
            'failure_code' => $this->failure_code,
            'failure_message' => $this->failure_message,
            'verified_at' => $this->verified_at?->toIso8601String(),
            'refunded_at' => $this->refunded_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
