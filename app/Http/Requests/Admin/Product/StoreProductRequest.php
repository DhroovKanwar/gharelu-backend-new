<?php

namespace App\Http\Requests\Admin\Product;

use Illuminate\Foundation\Http\FormRequest;

class StoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'slug' => ['nullable', 'string', 'max:255', 'unique:products,slug'],
            'name' => ['required', 'string', 'max:255'],
            'collection' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'long_description' => ['nullable', 'string'],
            'flavour' => ['nullable', 'string', 'max:255'],
            'base_price' => ['required', 'numeric', 'decimal:0,2', 'min:'.config('shop.min_price'), 'max:'.config('shop.max_price')],
            // Required for new products so shipping is never quoted from a guessed weight.
            'weight_grams' => ['required', 'integer', 'min:'.config('shop.min_weight_grams'), 'max:'.config('shop.max_weight_grams')],
            'old_price' => ['nullable', 'numeric', 'decimal:0,2', 'min:'.config('shop.min_price'), 'max:'.config('shop.max_price')],
            'ingredients' => ['nullable', 'array'],
            'ingredients.*' => ['string', 'max:100'],
            'allergens' => ['nullable', 'array'],
            'allergens.*' => ['string', 'max:100'],
            'rating_cached' => ['nullable', 'numeric', 'min:0', 'max:5'],
            'reviews_count_cached' => ['nullable', 'integer', 'min:0'],
            'is_featured' => ['sometimes', 'boolean'],
            'is_bestseller' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'stock_status' => ['sometimes', 'in:in_stock,low_stock,out_of_stock'],
        ];
    }
}
