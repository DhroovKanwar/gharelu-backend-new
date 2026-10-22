<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class AdminProductImageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'path' => $this->path,
            // Seeded products store a full URL or a storefront path (/images/...), which the
            // admin app serves from the storefront; uploads store a path on the public disk.
            'url' => preg_match('#^https?://#i', $this->path) || str_starts_with($this->path, '/images/')
                ? $this->path
                : Storage::disk('public')->url($this->path),
            'alt' => $this->alt,
            'sortOrder' => $this->sort_order,
            'isPrimary' => $this->is_primary,
        ];
    }
}
