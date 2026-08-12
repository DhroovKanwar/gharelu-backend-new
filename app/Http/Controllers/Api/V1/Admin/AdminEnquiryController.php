<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Enquiry\UpdateEnquiryStatusRequest;
use App\Http\Resources\Admin\AdminEnquiryResource;
use App\Models\Enquiry;
use Illuminate\Http\JsonResponse;

class AdminEnquiryController extends Controller
{
    public function index(): JsonResponse
    {
        $enquiries = Enquiry::query()->latest()->paginate(20);

        return response()->json([
            'success' => true,
            'message' => 'Enquiries retrieved successfully.',
            'data' => AdminEnquiryResource::collection($enquiries)->collection,
            'meta' => [
                'current_page' => $enquiries->currentPage(),
                'last_page' => $enquiries->lastPage(),
                'per_page' => $enquiries->perPage(),
                'total' => $enquiries->total(),
            ],
        ]);
    }

    public function show(Enquiry $enquiry): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'Enquiry retrieved successfully.',
            'data' => new AdminEnquiryResource($enquiry),
        ]);
    }

    public function updateStatus(UpdateEnquiryStatusRequest $request, Enquiry $enquiry): JsonResponse
    {
        $enquiry->update(['status' => $request->validated('status')]);

        return response()->json([
            'success' => true,
            'message' => 'Enquiry status updated successfully.',
            'data' => new AdminEnquiryResource($enquiry),
        ]);
    }
}
