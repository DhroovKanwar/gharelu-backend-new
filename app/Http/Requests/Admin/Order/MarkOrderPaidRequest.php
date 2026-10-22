<?php

namespace App\Http\Requests\Admin\Order;

use Illuminate\Foundation\Http\FormRequest;

class MarkOrderPaidRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'razorpay_payment_id' => is_string($this->razorpay_payment_id) ? trim($this->razorpay_payment_id) : $this->razorpay_payment_id,
            'remarks' => is_string($this->remarks) ? trim(strip_tags($this->remarks)) : $this->remarks,
        ]);
    }

    public function rules(): array
    {
        return [
            // Required for online orders (checked in the controller, it depends on the order).
            'razorpay_payment_id' => ['nullable', 'string', 'regex:/^pay_[A-Za-z0-9]{6,40}$/'],
            'remarks' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'razorpay_payment_id.regex' => 'Enter the Razorpay payment id exactly as shown in the dashboard (starts with pay_).',
        ];
    }
}
