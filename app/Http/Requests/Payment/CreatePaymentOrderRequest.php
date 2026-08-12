<?php

namespace App\Http\Requests\Payment;

use Illuminate\Foundation\Http\FormRequest;

class CreatePaymentOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Guest checkout must be able to pay too — ownership is enforced
        // in PaymentService::assertCanPay(), not here.
        return true;
    }

    public function rules(): array
    {
        return [
            'order_number' => ['required', 'string', 'exists:orders,order_number'],

            // Deliberately no 'amount' rule — even if the client sends one,
            // validated() only returns what's in these rules, so it never
            // reaches PaymentService. The amount always comes from
            // orders.total in the database.
        ];
    }
}
