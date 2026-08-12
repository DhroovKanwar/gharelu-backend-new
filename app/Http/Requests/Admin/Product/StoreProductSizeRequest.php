<?php

namespace App\Http\Requests\Admin\Product;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProductSizeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'label' => [
                'required', 'string', 'max:50',
                Rule::unique('product_sizes', 'label')->where('product_id', $this->route('product')->id),
            ],
            'price' => ['required', 'numeric', 'min:0'],
        ];
    }
}
