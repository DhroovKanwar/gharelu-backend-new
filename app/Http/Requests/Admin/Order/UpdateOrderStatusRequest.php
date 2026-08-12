<?php

namespace App\Http\Requests\Admin\Order;

use Illuminate\Foundation\Http\FormRequest;

class UpdateOrderStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Deliberately no 'payment_status' rule — even if sent, it's
            // dropped by validated() before it ever reaches the controller.
            // Payment status is only ever changed by PaymentService/webhooks.
            'order_status' => [
                'required',
                'in:new,confirmed,preparing,ready,out_for_delivery,completed,cancelled',
            ],
        ];
    }
}
