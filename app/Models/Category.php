<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Category extends Model
{
    use SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'parent_id',
        'name',
        'slug',
        'description',
        'image_path',
        'sort_order',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<Category, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order')->orderBy('name');
    }

    /**
     * @return HasMany<Product, $this>
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /**
     * Collect this category's ID plus all descendant category IDs cleanly (circular-safe).
     *
     * @return list<int>
     */
    public function descendantAndSelfIds(): array
    {
        $visited = [$this->id];
        $frontier = [$this->id];

        while ($frontier !== []) {
            $childIds = self::query()
                ->whereIn('parent_id', $frontier)
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all();

            $next = array_values(array_diff($childIds, $visited));
            $visited = array_merge($visited, $next);
            $frontier = $next;
        }

        return $visited;
    }

    /**
     * Prevent circular hierarchy when assigning a new parent_id.
     */
    public function wouldCreateCycle(?int $candidateParentId): bool
    {
        if ($candidateParentId === null) {
            return false;
        }

        if ($this->exists && $candidateParentId === $this->id) {
            return true;
        }

        $visited = $this->exists ? [$this->id] : [];
        $currentId = $candidateParentId;

        while ($currentId !== null) {
            if (in_array($currentId, $visited, true)) {
                return true;
            }
            $visited[] = $currentId;
            $parent = self::query()->whereKey($currentId)->value('parent_id');
            $currentId = $parent !== null ? (int) $parent : null;
        }

        return false;
    }
}
