<?php

namespace App\Http\Requests\Concerns;

trait ValidatesCartItems
{
    protected function cartItemRules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.product_slug' => ['required', 'string', 'exists:products,slug'],
            'items.*.size_label' => ['nullable', 'string', 'max:50'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:20'],
        ];
    }
}
