<?php

namespace App\Http\Requests\Admin\Dashboard;

use App\Services\Admin\AdminAnalyticsService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Validator;

class AdminAnalyticsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'date' => ['sometimes', 'date_format:Y-m-d'],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
        ];
    }

    /**
     * Keeps one request from scanning months of events.
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isEmpty() && $this->filled('from') && $this->filled('to')
                && Carbon::parse($this->input('from'))->diffInDays(Carbon::parse($this->input('to'))) >= AdminAnalyticsService::MAX_RANGE_DAYS) {
                $validator->errors()->add('to', 'Pick a range of at most '.AdminAnalyticsService::MAX_RANGE_DAYS.' days.');
            }
        }];
    }
}
