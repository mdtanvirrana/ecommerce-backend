<?php

namespace App\Http\Resources;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Product */
class ProductResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $primaryImage = $this->relationLoaded('images') ? $this->images->first() : null;

        return [
            'id' => $this->id,
            'category_id' => $this->category_id,
            'category' => new CategoryResource($this->whenLoaded('category')),
            'name' => $this->name,
            'slug' => $this->slug,
            'sku' => $this->sku,
            'description' => $this->description,
            'regular_price' => (string) $this->regular_price,
            'discount_price' => $this->discount_price !== null ? (string) $this->discount_price : null,
            'effective_price' => $this->effectivePrice(),
            'discount_percentage' => $this->discountPercentage(),
            'stock_quantity' => $this->stock_quantity,
            'low_stock_threshold' => $this->low_stock_threshold,
            'in_stock' => $this->stock_quantity > 0 && $this->is_active,
            'is_active' => (bool) $this->is_active,
            'is_featured' => (bool) $this->is_featured,
            'is_new_arrival' => (bool) $this->is_new_arrival,
            'is_top_selling' => (bool) $this->is_top_selling,
            'rating' => (string) $this->rating,
            'reviews_count' => $this->reviews_count,
            'colors' => $this->colors ?? [],
            'sizes' => $this->sizes ?? [],
            'primary_image_url' => $primaryImage?->fullUrl(),
            'images' => ProductImageResource::collection($this->whenLoaded('images')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
