<?php

namespace App\Http\Requests\Admin\Enquiry;

use Illuminate\Foundation\Http\FormRequest;

class UpdateEnquiryStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', 'in:new,in_progress,resolved'],
        ];
    }
}
