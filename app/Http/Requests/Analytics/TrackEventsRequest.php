<?php

namespace App\Http\Requests\Analytics;

use Illuminate\Foundation\Http\FormRequest;

class TrackEventsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'visitor_id' => ['required', 'string', 'min:8', 'max:64', 'regex:/^[A-Za-z0-9-]+$/'],
            'events' => ['required', 'array', 'min:1', 'max:20'],
            'events.*.type' => ['required', 'in:pageview,click'],
            'events.*.label' => ['required', 'string', 'max:120'],
            'events.*.path' => ['required', 'string', 'max:255'],
        ];
    }
}
