<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Admin-facing counterpart to the public ProductResource — exposes the real
 * numeric id (needed for size/image management calls) and every internal
 * field, including inactive/soft-deleted state.
 */
class AdminProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'categoryId' => $this->category_id,
            'category' => $this->whenLoaded('category', fn () => $this->category ? [
                'id' => $this->category->id,
                'name' => $this->category->name,
                'slug' => $this->category->slug,
            ] : null),
            'collection' => $this->collection,
            'description' => $this->description,
            'longDescription' => $this->long_description,
            'flavour' => $this->flavour,
            'basePrice' => (float) $this->base_price,
            'oldPrice' => $this->old_price !== null ? (float) $this->old_price : null,
            'ingredients' => $this->ingredients ?? [],
            'allergens' => $this->allergens ?? [],
            'ratingCached' => (float) $this->rating_cached,
            'reviewsCountCached' => $this->reviews_count_cached,
            'isFeatured' => $this->is_featured,
            'isBestseller' => $this->is_bestseller,
            'isActive' => $this->is_active,
            'stockStatus' => $this->stock_status,
            'sizes' => AdminProductSizeResource::collection($this->whenLoaded('sizes')),
            'images' => AdminProductImageResource::collection($this->whenLoaded('images')),
            'deletedAt' => $this->deleted_at?->toIso8601String(),
            'createdAt' => $this->created_at?->toIso8601String(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }
}
