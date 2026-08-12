<?php

namespace App\Http\Requests\Admin\Product;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProductSizeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'label' => [
                'sometimes', 'required', 'string', 'max:50',
                Rule::unique('product_sizes', 'label')
                    ->where('product_id', $this->route('product')->id)
                    ->ignore($this->route('size')),
            ],
            'price' => ['sometimes', 'required', 'numeric', 'min:0'],
        ];
    }
}
