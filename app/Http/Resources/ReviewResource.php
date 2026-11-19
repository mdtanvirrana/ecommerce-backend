<?php

namespace App\Http\Resources;

use App\Models\Review;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Review */
class ReviewResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'product' => $this->whenLoaded('product', fn (): ?array => $this->product ? [
                'id' => $this->product->id,
                'name' => $this->product->name,
                'slug' => $this->product->slug,
                'sku' => $this->product->sku,
            ] : null),
            'user_id' => $this->user_id,
            'reviewer_name' => $this->reviewer_name,
            'rating' => (int) $this->rating,
            'comment' => $this->comment,
            'is_verified_purchase' => (bool) $this->is_verified_purchase,
            'is_approved' => (bool) $this->is_approved,
            'is_featured' => (bool) $this->is_featured,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
