<?php

namespace App\Http\Resources;

use App\Models\Coupon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Coupon */
class CouponResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'type' => $this->type->value,
            'value' => (string) $this->value,
            'minimum_order_amount' => (string) $this->minimum_order_amount,
            'maximum_discount_amount' => $this->maximum_discount_amount !== null ? (string) $this->maximum_discount_amount : null,
            'usage_limit' => $this->usage_limit,
            'per_customer_usage_limit' => $this->per_customer_usage_limit,
            'used_count' => $this->used_count,
            'starts_at' => $this->starts_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'is_active' => (bool) $this->is_active,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
