<?php

namespace App\Http\Requests\Admin\Product;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'category_id' => ['sometimes', 'required', 'integer', 'exists:categories,id'],
            'slug' => [
                'sometimes', 'nullable', 'string', 'max:255',
                Rule::unique('products', 'slug')->ignore($this->route('product')),
            ],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'collection' => ['sometimes', 'nullable', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'long_description' => ['sometimes', 'nullable', 'string'],
            'flavour' => ['sometimes', 'nullable', 'string', 'max:255'],
            'base_price' => ['sometimes', 'required', 'numeric', 'min:0'],
            'old_price' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'ingredients' => ['sometimes', 'nullable', 'array'],
            'ingredients.*' => ['string', 'max:100'],
            'allergens' => ['sometimes', 'nullable', 'array'],
            'allergens.*' => ['string', 'max:100'],
            'rating_cached' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:5'],
            'reviews_count_cached' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'is_featured' => ['sometimes', 'boolean'],
            'is_bestseller' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'stock_status' => ['sometimes', 'in:in_stock,low_stock,out_of_stock'],
        ];
    }
}
