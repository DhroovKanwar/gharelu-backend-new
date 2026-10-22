<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Analytics\TrackEventsRequest;
use App\Models\AnalyticsEvent;
use Illuminate\Http\Response;

class TrackController extends Controller
{
    public function store(TrackEventsRequest $request): Response
    {
        $data = $request->validated();
        $now = now();

        AnalyticsEvent::insert(array_map(fn (array $event) => [
            'visitor_id' => $data['visitor_id'],
            'type' => $event['type'],
            'label' => trim($event['label']),
            // Query strings can carry order numbers or phone numbers; keep the path only.
            'path' => strtok($event['path'], '?#') ?: '/',
            'created_at' => $now,
        ], $data['events']));

        return response()->noContent();
    }
}
