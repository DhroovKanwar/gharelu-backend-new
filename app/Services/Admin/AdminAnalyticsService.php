<?php

namespace App\Services\Admin;

use App\Models\AnalyticsEvent;
use App\Models\Order;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class AdminAnalyticsService
{
    // The shop is in India, so "today" means the Indian calendar day, not UTC.
    private const TIMEZONE = 'Asia/Kolkata';

    private const MAX_EVENTS = 20000;

    public function forDay(?string $date): array
    {
        $day = $date ? Carbon::parse($date, self::TIMEZONE) : Carbon::now(self::TIMEZONE);
        $from = $day->copy()->startOfDay()->utc();
        $to = $day->copy()->endOfDay()->utc();

        $events = AnalyticsEvent::whereBetween('created_at', [$from, $to])
            ->orderBy('created_at')
            ->limit(self::MAX_EVENTS)
            ->get(['visitor_id', 'type', 'label', 'created_at']);

        $orders = Order::whereBetween('created_at', [$from, $to]);
        $placed = (clone $orders)->count();
        $paid = (clone $orders)->where('payment_status', 'paid')->count();

        $visitors = $events->pluck('visitor_id')->unique()->count();

        return [
            'date' => $day->toDateString(),
            'visitors' => $visitors,
            'pageviews' => $events->where('type', 'pageview')->count(),
            'clicks' => $events->where('type', 'click')->count(),
            'orders' => ['placed' => $placed, 'paid' => $paid],
            'conversionRate' => $visitors > 0 ? round($placed / $visitors * 100, 1) : 0.0,
            'topPages' => $this->top($events->where('type', 'pageview'), 'views'),
            'topClicks' => $this->top($events->where('type', 'click'), 'clicks'),
            'visitorActivity' => $this->visitorActivity($events),
        ];
    }

    private function top(Collection $events, string $countKey): array
    {
        return $events->groupBy('label')
            ->map(fn ($group, $label) => [
                'label' => (string) $label,
                $countKey => $group->count(),
                'visitors' => $group->pluck('visitor_id')->unique()->count(),
            ])
            ->sortByDesc($countKey)
            ->take(15)
            ->values()
            ->all();
    }

    private function visitorActivity(Collection $events): array
    {
        return $events->groupBy('visitor_id')
            ->map(function ($group, $id) {
                $pageviews = $group->where('type', 'pageview');
                $clicks = $group->where('type', 'click');

                return [
                    // Short anonymous tag, enough to tell visitors apart without identifying anyone.
                    'visitor' => strtoupper(substr((string) $id, 0, 6)),
                    'firstSeen' => $group->first()->created_at->toIso8601String(),
                    'lastSeen' => $group->last()->created_at->toIso8601String(),
                    'pageviews' => $pageviews->count(),
                    'clicks' => $clicks->count(),
                    'pages' => $pageviews->pluck('label')->unique()->take(8)->values()->all(),
                    'clicked' => $clicks->pluck('label')->unique()->take(8)->values()->all(),
                ];
            })
            ->sortByDesc('lastSeen')
            ->take(50)
            ->values()
            ->all();
    }
}
