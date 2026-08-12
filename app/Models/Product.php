<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'category_id',
        'slug',
        'name',
        'collection',
        'description',
        'long_description',
        'flavour',
        'base_price',
        'old_price',
        'ingredients',
        'allergens',
        'rating_cached',
        'reviews_count_cached',
        'is_featured',
        'is_bestseller',
        'is_active',
        'stock_status',
    ];

    protected $casts = [
        'ingredients' => 'array',
        'allergens' => 'array',
        'base_price' => 'decimal:2',
        'old_price' => 'decimal:2',
        'rating_cached' => 'decimal:2',
        'reviews_count_cached' => 'integer',
        'is_featured' => 'boolean',
        'is_bestseller' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function sizes(): HasMany
    {
        return $this->hasMany(ProductSize::class)->orderBy('sort_order');
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('sort_order');
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }
}
