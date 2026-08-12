<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Newsletter\AdminNewsletterIndexRequest;
use App\Http\Resources\Admin\AdminNewsletterSubscriberResource;
use App\Models\NewsletterSubscriber;
use Illuminate\Http\JsonResponse;

class AdminNewsletterSubscriberController extends Controller
{
    public function index(AdminNewsletterIndexRequest $request): JsonResponse
    {
        $data = $request->validated();
        $perPage = $data['per_page'] ?? 50;

        $query = NewsletterSubscriber::query();

        if (! empty($data['search'])) {
            $query->where('email', 'like', '%'.$data['search'].'%');
        }

        $subscribers = $query->latest('subscribed_at')->paginate($perPage);

        return response()->json([
            'success' => true,
            'message' => 'Newsletter subscribers retrieved successfully.',
            'data' => AdminNewsletterSubscriberResource::collection($subscribers)->collection,
            'meta' => [
                'current_page' => $subscribers->currentPage(),
                'last_page' => $subscribers->lastPage(),
                'per_page' => $subscribers->perPage(),
                'total' => $subscribers->total(),
            ],
        ]);
    }
}
