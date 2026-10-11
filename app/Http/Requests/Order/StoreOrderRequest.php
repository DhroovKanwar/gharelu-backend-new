<?php

namespace App\Http\Requests\Order;

use App\Http\Requests\Concerns\ValidatesCartItems;
use Illuminate\Foundation\Http\FormRequest;

class StoreOrderRequest extends FormRequest
{
    use ValidatesCartItems;

    public function authorize(): bool
    {
        // Guest checkout is allowed — no auth check here.
        return true;
    }

    public function rules(): array
    {
        return array_merge($this->cartItemRules(), [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => [
                'required',
                'string',
                'max:20',
                // Same rule as the React checkout: the last 10 digits must be
                // a valid Indian mobile number (starts 6-9).
                function (string $attribute, mixed $value, \Closure $fail) {
                    $digits = preg_replace('/\D/', '', $value);
                    if (strlen($digits) < 10 || ! preg_match('/^[6-9][0-9]{9}$/', substr($digits, -10))) {
                        $fail('Enter a valid 10-digit Indian mobile number.');
                    }
                },
            ],

            'delivery_mode' => ['required', 'in:delivery,pickup'],
            'pickup_type' => ['required_if:delivery_mode,pickup', 'nullable', 'in:dine_in,parcel'],

            'address_line_1' => ['required_if:delivery_mode,delivery', 'nullable', 'string', 'max:255'],
            'address_line_2' => ['nullable', 'string', 'max:255'],
            'city' => ['required_if:delivery_mode,delivery', 'nullable', 'string', 'max:100'],
            'state' => ['required_if:delivery_mode,delivery', 'nullable', 'string', 'max:100'],
            'pincode' => ['required_if:delivery_mode,delivery', 'nullable', 'regex:/^[1-9][0-9]{5}$/'],
            'landmark' => ['nullable', 'string', 'max:255'],

            'scheduled_date' => ['required', 'date', 'after_or_equal:today'],
            'scheduled_time' => ['required', 'date_format:H:i'],

          'payment_method' => [
                'required',
                'in:online,cod',
                // Cash on Delivery is not offered for delivery orders.
                function (string $attribute, mixed $value, \Closure $fail) {
                    if ($value === 'cod' && $this->input('delivery_mode') === 'delivery') {
                        $fail('Cash on delivery is not available for delivery orders.');
                    }
                },
            ],

            'notes' => ['nullable', 'string', 'max:1000'],

            // Deliberately no rules for price / subtotal / delivery_fee /
            // total / order_status / payment_status — even if the client
            // sends them, validated() below only returns what's in $rules,
            // so they're silently dropped before OrderService ever sees them.
        ]);
    }
}
