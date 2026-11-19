<?php

namespace App\Http\Resources;

use App\Models\OrderItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin OrderItem */
class OrderItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $imageUrl = null;
        if ($this->relationLoaded('product') && $this->product !== null && $this->product->relationLoaded('images')) {
            $imageUrl = $this->product->images->first()?->fullUrl();
        }

        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'product_name' => $this->product_name,
            'product_sku' => $this->product_sku,
            'selected_color' => $this->selected_color,
            'selected_size' => $this->selected_size,
            'unit_price' => (string) $this->unit_price,
            'quantity' => $this->quantity,
            'line_total' => (string) $this->line_total,
            'image_url' => $imageUrl,
        ];
    }
}
