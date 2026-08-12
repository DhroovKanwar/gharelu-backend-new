<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminCategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'imagePath' => $this->image_path,
            'sortOrder' => $this->sort_order,
            'isActive' => $this->is_active,
            'productsCount' => $this->when(
                $this->relationLoaded('products') || isset($this->products_count),
                fn () => $this->products_count ?? $this->products->count(),
            ),
            'createdAt' => $this->created_at?->toIso8601String(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }
}
