<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Review extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'product_id',
        'user_id',
        'reviewer_name',
        'rating',
        'comment',
        'is_verified_purchase',
        'is_approved',
        'is_featured',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rating' => 'integer',
            'is_verified_purchase' => 'boolean',
            'is_approved' => 'boolean',
            'is_featured' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    /**
     * Keep product rating and exact approved review count synchronized with MySQL.
     */
    public static function syncProductRating(int $productId): void
    {
        $stats = self::query()
            ->where('product_id', $productId)
            ->where('is_approved', true)
            ->selectRaw('COUNT(*) as total_count, COALESCE(AVG(rating), 0) as avg_rating')
            ->first();

        $count = (int) ($stats->total_count ?? 0);
        $avg = $count > 0 ? number_format((float) $stats->avg_rating, 2, '.', '') : '0.00';

        Product::query()->whereKey($productId)->update([
            'rating' => $avg,
            'reviews_count' => $count,
        ]);
    }
}
