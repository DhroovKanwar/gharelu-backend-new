<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Lead\StoreEnquiryRequest;
use App\Http\Requests\Lead\SubscribeNewsletterRequest;
use App\Models\Enquiry;
use App\Models\NewsletterSubscriber;
use Illuminate\Http\JsonResponse;

/**
 * Public storefront forms: the contact form and the newsletter signup.
 */
class LeadController extends Controller
{
    public function enquiry(StoreEnquiryRequest $request): JsonResponse
    {
        $data = $request->validated();

        Enquiry::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'message' => $data['message'],
            'source_page' => $data['source_page'] ?? 'contact',
            'status' => 'new',
        ]);

        return response()->json(['success' => true, 'message' => 'Thanks! We will be in touch soon.'], 201);
    }

    public function subscribe(SubscribeNewsletterRequest $request): JsonResponse
    {
        // Signing up twice is not an error: the visitor just sees the same thank-you.
        NewsletterSubscriber::firstOrCreate(
            ['email' => $request->validated('email')],
            ['subscribed_at' => now()],
        );

        return response()->json(['success' => true, 'message' => 'You are on the list.'], 201);
    }
}
