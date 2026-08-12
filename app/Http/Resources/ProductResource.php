<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Maps DB columns to the exact shape the frontend's `products.json` already
 * used, so pointing the frontend at this API later requires no component
 * changes:
 *
 * id, name, category, collection, price, oldPrice, image, gallery, tag,
 * rating, reviews, featured, bestseller, description, longDescription,
 * flavour, sizes, ingredients, allergens.
 */
class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $images = $this->images; // eager-loaded, ordered by sort_order

        return [
            'id' => $this->slug,
            'name' => $this->name,
            'category' => $this->category?->name,
            'collection' => $this->collection,
            'price' => (float) $this->base_price,
            'oldPrice' => $this->old_price !== null ? (float) $this->old_price : null,
            'image' => $images->firstWhere('is_primary', true)?->path
                ?? $images->first()?->path,
            'gallery' => $images->pluck('path')->values(),
            'tag' => $this->is_bestseller ? 'Bestseller' : null,
            'rating' => (float) $this->rating_cached,
            'reviews' => $this->reviews_count_cached,
            'featured' => $this->is_featured,
            'bestseller' => $this->is_bestseller,
            'description' => $this->description,
            'longDescription' => $this->long_description,
            'flavour' => $this->flavour,
            'sizes' => $this->sizes->map(fn ($size) => [
                'label' => $size->label,
                'price' => (float) $size->price,
            ])->values(),
            'ingredients' => $this->ingredients ?? [],
            'allergens' => $this->allergens ?? [],
        ];
    }
}
