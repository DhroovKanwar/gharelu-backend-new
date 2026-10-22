<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Dashboard\AdminAnalyticsRequest;
use App\Services\Admin\AdminAnalyticsService;
use Illuminate\Http\JsonResponse;

class AdminAnalyticsController extends Controller
{
    public function __construct(private readonly AdminAnalyticsService $analytics) {}

    public function index(AdminAnalyticsRequest $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'Analytics retrieved successfully.',
            'data' => $this->analytics->forDay($request->validated('date')),
        ]);
    }
}
