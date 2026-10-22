<?php

namespace App\Http\Requests\Lead;

use Illuminate\Foundation\Http\FormRequest;

class StoreEnquiryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Strip HTML from free text and reduce the phone to bare digits (+91 / leading 0 removed).
     */
    protected function prepareForValidation(): void
    {
        $clean = [];

        foreach (['name', 'email', 'message', 'source_page'] as $field) {
            if (is_string($this->input($field))) {
                $clean[$field] = trim(strip_tags($this->input($field)));
            }
        }

        if (is_string($this->input('phone'))) {
            $digits = preg_replace('/\D/', '', $this->input('phone'));
            if (strlen($digits) === 12 && str_starts_with($digits, '91')) {
                $digits = substr($digits, 2);
            } elseif (strlen($digits) === 11 && $digits[0] === '0') {
                $digits = substr($digits, 1);
            }
            $clean['phone'] = $digits ?: null;
        }

        $this->merge($clean);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email:rfc', 'max:255'],
            'phone' => ['nullable', 'regex:/^[6-9][0-9]{9}$/'],
            'message' => ['required', 'string', 'min:10', 'max:2000'],
            'source_page' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return ['phone.regex' => 'Enter a valid 10-digit Indian mobile number.'];
    }
}
