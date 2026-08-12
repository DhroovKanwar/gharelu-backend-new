<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CustomCake\UpdateCustomCakeStatusRequest;
use App\Http\Resources\Admin\AdminCustomCakeRequestResource;
use App\Models\CustomCakeRequest;
use Illuminate\Http\JsonResponse;

class AdminCustomCakeRequestController extends Controller
{
    public function index(): JsonResponse
    {
        $requests = CustomCakeRequest::query()->latest()->paginate(20);

        return response()->json([
            'success' => true,
            'message' => 'Custom cake requests retrieved successfully.',
            'data' => AdminCustomCakeRequestResource::collection($requests)->collection,
            'meta' => [
                'current_page' => $requests->currentPage(),
                'last_page' => $requests->lastPage(),
                'per_page' => $requests->perPage(),
                'total' => $requests->total(),
            ],
        ]);
    }

    public function show(CustomCakeRequest $customCakeRequest): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'Custom cake request retrieved successfully.',
            'data' => new AdminCustomCakeRequestResource($customCakeRequest),
        ]);
    }

    public function updateStatus(UpdateCustomCakeStatusRequest $request, CustomCakeRequest $customCakeRequest): JsonResponse
    {
        $customCakeRequest->update(['status' => $request->validated('status')]);

        return response()->json([
            'success' => true,
            'message' => 'Custom cake request status updated successfully.',
            'data' => new AdminCustomCakeRequestResource($customCakeRequest),
        ]);
    }
}
