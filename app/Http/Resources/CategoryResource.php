<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `count` is computed (active products in this category), not stored —
 * so it's always accurate instead of a cached number that can drift.
 * `span` (a layout hint in the old static JSON) is intentionally dropped;
 * it's a frontend-only cosmetic value with no backend meaning.
 */
class CategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->slug,
            'name' => $this->name,
            'count' => $this->products_count ?? $this->products()->where('is_active', true)->count(),
            'image' => $this->image_path,
        ];
    }
}
