<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Dashboard\AdminDashboardIndexRequest;
use App\Services\Admin\AdminDashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

class AdminDashboardController extends Controller
{
    public function __construct(
        private readonly AdminDashboardService $dashboardService,
    ) {}

    public function index(AdminDashboardIndexRequest $request): JsonResponse
    {
        $data = $request->validated();
        $range = $this->resolveRange($data);

        return response()->json([
            'success' => true,
            'message' => 'Dashboard statistics retrieved successfully.',
            'data' => [
                ...$this->dashboardService->stats($range),
                'bestSellers' => $this->dashboardService->bestSellers($range),
            ],
        ]);
    }

    /**
     * No `period` means "all-time" (null) — the original, unscoped behavior.
     */
    private function resolveRange(array $data): ?array
    {
        return match ($data['period'] ?? null) {
            '7d' => ['from' => today()->subDays(6)->startOfDay(), 'to' => now()],
            '30d' => ['from' => today()->subDays(29)->startOfDay(), 'to' => now()],
            'month' => ['from' => now()->startOfMonth(), 'to' => now()],
            'year' => ['from' => now()->startOfYear(), 'to' => now()],
            'custom' => ['from' => Carbon::parse($data['from'])->startOfDay(), 'to' => Carbon::parse($data['to'])->endOfDay()],
            default => null,
        };
    }
}
