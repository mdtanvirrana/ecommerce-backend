<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'category_id',
        'name',
        'slug',
        'sku',
        'description',
        'regular_price',
        'discount_price',
        'stock_quantity',
        'low_stock_threshold',
        'is_active',
        'is_featured',
        'is_new_arrival',
        'is_top_selling',
        'rating',
        'reviews_count',
        'colors',
        'sizes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'regular_price' => 'decimal:2',
            'discount_price' => 'decimal:2',
            'stock_quantity' => 'integer',
            'low_stock_threshold' => 'integer',
            'is_active' => 'boolean',
            'is_featured' => 'boolean',
            'is_new_arrival' => 'boolean',
            'is_top_selling' => 'boolean',
            'rating' => 'decimal:2',
            'reviews_count' => 'integer',
            'colors' => 'array',
            'sizes' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * @return HasMany<ProductImage, $this>
     */
    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderByDesc('is_primary')->orderBy('sort_order');
    }

    /**
     * @return HasMany<InventoryTransaction, $this>
     */
    public function inventoryTransactions(): HasMany
    {
        return $this->hasMany(InventoryTransaction::class);
    }

    /**
     * @return HasMany<Review, $this>
     */
    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    /**
     * Server-side authoritative unit price (discount_price if valid and lower than regular_price).
     */
    public function effectivePrice(): string
    {
        $regular = Money::normalize((string) $this->regular_price);

        if ($this->discount_price !== null) {
            $discount = Money::normalize((string) $this->discount_price);
            if (Money::compare($discount, '0.00') > 0 && Money::compare($discount, $regular) < 0) {
                return $discount;
            }
        }

        return $regular;
    }

    public function discountPercentage(): ?int
    {
        if ($this->discount_price === null) {
            return null;
        }

        $regular = Money::normalize((string) $this->regular_price);
        $discount = Money::normalize((string) $this->discount_price);

        if (Money::compare($regular, '0.00') <= 0 || Money::compare($discount, $regular) >= 0) {
            return null;
        }

        $diff = Money::sub($regular, $discount);
        $pct = bcdiv(bcmul($diff, '100', 4), $regular, 2);

        return (int) round((float) $pct);
    }

    public function isInStock(int $requestedQty = 1): bool
    {
        return $this->is_active && $this->stock_quantity >= $requestedQty;
    }
}
