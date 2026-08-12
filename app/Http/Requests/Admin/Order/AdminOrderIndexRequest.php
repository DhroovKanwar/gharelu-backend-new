<?php

namespace App\Http\Requests\Admin\Order;

use Illuminate\Foundation\Http\FormRequest;

class AdminOrderIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'order_status' => ['sometimes', 'in:new,confirmed,preparing,ready,out_for_delivery,completed,cancelled'],
            'payment_status' => ['sometimes', 'in:pending,paid,failed,refunded'],
            'payment_method' => ['sometimes', 'in:online,cod'],
            'delivery_mode' => ['sometimes', 'in:delivery,pickup'],
            'date' => ['sometimes', 'date'],
            'search' => ['sometimes', 'string', 'max:255'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
