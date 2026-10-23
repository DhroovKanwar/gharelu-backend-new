<?php

namespace App\Http\Requests\Order;

use App\Http\Requests\Concerns\ValidatesCartItems;
use App\Models\Order;
use Illuminate\Foundation\Http\FormRequest;

class StoreOrderRequest extends FormRequest
{
    use ValidatesCartItems;

    private const MAX_ORDERS_PER_PHONE_PER_HOUR = 5;

    public function authorize(): bool
    {
        // Guest checkout is allowed — no auth check here.
        return true;
    }

    /**
     * Clean input before the rules run: strip HTML from free text, collapse the
     * phone to bare digits (so "+91 98765 43210" and "098765-43210" both become
     * 9876543210) and treat a blank email as "not given".
     */
    protected function prepareForValidation(): void
    {
        $clean = [];

        foreach (['first_name', 'last_name', 'recipient_first_name', 'recipient_last_name', 'address_line_1', 'address_line_2', 'city', 'state', 'landmark', 'notes'] as $field) {
            if (is_string($this->input($field))) {
                $clean[$field] = trim(strip_tags($this->input($field)));
            }
        }

        // Phones come as a country code (+91 by default) plus the number without it.
        $clean['phone_country_code'] = $this->countryCode('phone_country_code');
        $clean['recipient_phone_country_code'] = $this->countryCode('recipient_phone_country_code');

        if (is_string($this->input('phone'))) {
            $clean['phone'] = $this->nationalNumber($this->input('phone'), $clean['phone_country_code']);
        }

        if (is_string($this->input('recipient_phone'))) {
            $clean['recipient_phone'] = $this->nationalNumber($this->input('recipient_phone'), $clean['recipient_phone_country_code']);
        }

        if (is_string($this->input('email'))) {
            $clean['email'] = trim($this->input('email')) ?: null;
        }

        $this->merge($clean);
    }

    /** "+91", "91" or " +44 " become "+91" / "+44"; anything unusable falls back to +91. */
    private function countryCode(string $field): string
    {
        $raw = $this->input($field);
        $digits = is_string($raw) ? preg_replace('/\D/', '', $raw) : '';

        return $digits === '' ? '+91' : '+'.$digits;
    }

    /** The number without country code or trunk "0"; for +91 a typed "+91 98765 43210" or "098765..." is cleaned too. */
    private function nationalNumber(string $raw, string $countryCode): string
    {
        $digits = preg_replace('/\D/', '', $raw);

        if ($countryCode === '+91') {
            if (strlen($digits) === 12 && str_starts_with($digits, '91')) {
                return substr($digits, 2);
            }
            if (strlen($digits) === 11 && $digits[0] === '0') {
                return substr($digits, 1);
            }

            return $digits;
        }

        return preg_replace('/^0+/', '', $digits);
    }

    /** Indian numbers are exactly 10 digits starting 6-9; other countries 6-14 digits. */
    private function phoneRule(string $countryCodeField): string
    {
        return $this->countryCode($countryCodeField) === '+91' ? 'regex:/^[6-9][0-9]{9}$/' : 'regex:/^[0-9]{6,14}$/';
    }

    public function messages(): array
    {
        return [
            'phone.regex' => 'Enter a valid phone number for the selected country code.',
            'recipient_phone.regex' => 'Enter a valid recipient phone number for the selected country code.',
            'phone_country_code.regex' => 'Choose a valid country code.',
            'recipient_phone_country_code.in' => 'The recipient needs an Indian (+91) mobile number.',
            'recipient_first_name.regex' => 'Names can only contain letters, spaces, dots, apostrophes and hyphens.',
            'recipient_last_name.regex' => 'Names can only contain letters, spaces, dots, apostrophes and hyphens.',
            'first_name.regex' => 'Names can only contain letters, spaces, dots, apostrophes and hyphens.',
            'last_name.regex' => 'Names can only contain letters, spaces, dots, apostrophes and hyphens.',
        ];
    }

    public function rules(): array
    {
        return array_merge($this->cartItemRules(), [
            'first_name' => ['required', 'string', 'max:50', "regex:/^[\p{L}\p{M}\s.'-]+$/u"],
            'last_name' => ['nullable', 'string', 'max:50', "regex:/^[\p{L}\p{M}\s.'-]+$/u"],
            // Optional: customers can order with just a phone number.
            'email' => ['nullable', 'email:rfc', 'max:255'],
            'phone' => [
                'required',
                'string',
                // Exactly 10 digits, starting 6-9 (prepareForValidation already removed +91, 0 and punctuation).
                $this->phoneRule('phone_country_code'),
                // Anti-spam: a single number can't flood the shop with orders.
                function (string $attribute, mixed $value, \Closure $fail) {
                    // Malformed values (arrays, odd text) are rejected by the rules above; don't build a query from them.
                    if (! is_string($value) || ! preg_match('/^[0-9]{6,14}$/', $value)) {
                        return;
                    }

                    $recent = Order::where('guest_phone', 'like', '%'.$value)
                        ->where('created_at', '>=', now()->subHour())
                        ->count();
                    if ($recent >= self::MAX_ORDERS_PER_PHONE_PER_HOUR) {
                        $fail('Too many orders from this number. Please call us to place more orders.');
                    }
                },
            ],

            'phone_country_code' => ['nullable', 'regex:/^\+[1-9][0-9]{0,3}$/'],

            // Who the delivery is for (the sender may be gifting): required for delivery, ignored for pickup.
            'recipient_first_name' => ['required_if:delivery_mode,delivery', 'nullable', 'string', 'max:50', "regex:/^[\p{L}\p{M}\s.'-]+$/u"],
            'recipient_last_name' => ['nullable', 'string', 'max:50', "regex:/^[\p{L}\p{M}\s.'-]+$/u"],
            // A parcel is delivered inside India, so the courier needs an Indian number for the recipient.
            'recipient_phone_country_code' => ['nullable', 'in:+91'],
            'recipient_phone' => ['required_if:delivery_mode,delivery', 'nullable', 'string', $this->phoneRule('recipient_phone_country_code')],

            'delivery_mode' => ['required', 'in:delivery,pickup'],
            'pickup_type' => ['required_if:delivery_mode,pickup', 'nullable', 'in:dine_in,parcel'],

            'address_line_1' => ['required_if:delivery_mode,delivery', 'nullable', 'string', 'max:255'],
            'address_line_2' => ['nullable', 'string', 'max:255'],
            'city' => ['required_if:delivery_mode,delivery', 'nullable', 'string', 'max:100'],
            'state' => ['required_if:delivery_mode,delivery', 'nullable', 'string', 'max:100'],
            'pincode' => ['required_if:delivery_mode,delivery', 'nullable', 'regex:/^[1-9][0-9]{5}$/'],
            'landmark' => ['nullable', 'string', 'max:255'],

            // 'bail' + 'string': the date rules crash (500) on a number instead of rejecting it.
            'scheduled_date' => ['bail', 'required', 'string', 'date', 'after_or_equal:today', 'before_or_equal:'.now()->addDays(90)->toDateString()],
            // Pickup has a time slot; delivery is scheduled by date only (the courier decides the time).
            'scheduled_time' => ['required_if:delivery_mode,pickup', 'nullable', 'date_format:H:i'],

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
