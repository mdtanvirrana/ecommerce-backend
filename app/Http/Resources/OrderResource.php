<?php

namespace App\Http\Resources;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Order */
class OrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_number' => $this->order_number,
            'user_id' => $this->user_id,
            'customer' => new CustomerResource($this->whenLoaded('user')),
            'status' => $this->status->value,
            'allowed_transitions' => array_map(fn ($s) => $s->value, $this->status->allowedTransitions()),
            'is_cancellable' => $this->status->isCancellable(),
            'payment_status' => $this->payment_status->value,
            'coupon_code' => $this->coupon_code,
            'subtotal' => (string) $this->subtotal,
            'discount_amount' => (string) $this->discount_amount,
            'shipping_amount' => (string) $this->shipping_amount,
            'total_amount' => (string) $this->total_amount,
            'currency' => $this->currency,
            'shipping' => [
                'full_name' => $this->shipping_full_name,
                'email' => $this->shipping_email,
                'phone' => $this->shipping_phone,
                'address' => $this->shipping_address,
                'city' => $this->shipping_city,
                'postal_code' => $this->shipping_postal_code,
                'delivery_instructions' => $this->delivery_instructions,
            ],
            'items' => OrderItemResource::collection($this->whenLoaded('items')),
            'items_count' => $this->whenCounted('items'),
            'latest_payment' => new PaymentResource($this->whenLoaded('latestPayment')),
            'payments' => PaymentResource::collection($this->whenLoaded('payments')),
            'paid_at' => $this->paid_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'cancellation_reason' => $this->cancellation_reason,
            'stock_deducted_at' => $this->stock_deducted_at?->toIso8601String(),
            'stock_restored_at' => $this->stock_restored_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
