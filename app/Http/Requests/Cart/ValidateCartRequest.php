<?php

namespace App\Http\Requests\Cart;

use App\Http\Requests\Concerns\ValidatesCartItems;
use Illuminate\Foundation\Http\FormRequest;

class ValidateCartRequest extends FormRequest
{
    use ValidatesCartItems;

    public function authorize(): bool
    {
        // Price preview is public — no auth required.
        return true;
    }

    public function rules(): array
    {
        return array_merge($this->cartItemRules(), [
            'delivery_mode' => ['sometimes', 'in:delivery,pickup'],
        ]);
    }
}
