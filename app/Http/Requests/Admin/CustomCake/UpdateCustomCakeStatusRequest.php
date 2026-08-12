<?php

namespace App\Http\Requests\Admin\CustomCake;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCustomCakeStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', 'in:new,reviewing,quoted,confirmed,rejected'],
        ];
    }
}
