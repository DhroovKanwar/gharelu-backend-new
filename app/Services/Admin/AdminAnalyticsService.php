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

    private const MAX_EVENTS = 100000;

    public const MAX_RANGE_DAYS = 92;

    public function forDay(?string $date): array
    {
        return $this->forRange($date, $date);
    }

    /**
     * Totals for the Indian calendar days from..to (inclusive); either missing means "today".
     */
    public function forRange(?string $fromDate, ?string $toDate): array
    {
        $first = $fromDate ? Carbon::parse($fromDate, self::TIMEZONE) : Carbon::now(self::TIMEZONE);
        $last = $toDate ? Carbon::parse($toDate, self::TIMEZONE) : $first->copy();
        $from = $first->copy()->startOfDay()->utc();
        $to = $last->copy()->endOfDay()->utc();

        $events = AnalyticsEvent::whereBetween('created_at', [$from, $to])
            ->orderBy('created_at')
            ->limit(self::MAX_EVENTS)
            ->get(['visitor_id', 'type', 'label', 'created_at']);

        $orders = Order::whereBetween('created_at', [$from, $to]);
        $placed = (clone $orders)->count();
        $paid = (clone $orders)->where('payment_status', 'paid')->count();

        $visitors = $events->pluck('visitor_id')->unique()->count();

        return [
            'date' => $first->toDateString(),
            'from' => $first->toDateString(),
            'to' => $last->toDateString(),
            'truncated' => $events->count() >= self::MAX_EVENTS,
            'visitors' => $visitors,
            'pageviews' => $events->where('type', 'pageview')->count(),
            'clicks' => $events->where('type', 'click')->count(),
            'orders' => ['placed' => $placed, 'paid' => $paid],
            'conversionRate' => $visitors > 0 ? round($placed / $visitors * 100, 1) : 0.0,
            'topPages' => $this->top($events->where('type', 'pageview'), 'views'),
            'topClicks' => $this->top($events->where('type', 'click'), 'clicks'),
            'daily' => $this->daily($events, $first, $last),
            'visitorActivity' => $this->visitorActivity($events),
        ];
    }

    /**
     * One row per Indian calendar day in the range (days with no traffic included).
     */
    private function daily(Collection $events, Carbon $first, Carbon $last): array
    {
        $byDay = $events->groupBy(fn ($e) => $e->created_at->copy()->setTimezone(self::TIMEZONE)->toDateString());

        $orders = Order::whereBetween('created_at', [$first->copy()->startOfDay()->utc(), $last->copy()->endOfDay()->utc()])
            ->get(['created_at', 'payment_status'])
            ->groupBy(fn ($o) => $o->created_at->copy()->setTimezone(self::TIMEZONE)->toDateString());

        $rows = [];
        for ($day = $first->copy()->startOfDay(); $day->lte($last); $day->addDay()) {
            $key = $day->toDateString();
            $dayEvents = $byDay->get($key, collect());
            $dayOrders = $orders->get($key, collect());

            $rows[] = [
                'date' => $key,
                'visitors' => $dayEvents->pluck('visitor_id')->unique()->count(),
                'pageviews' => $dayEvents->where('type', 'pageview')->count(),
                'clicks' => $dayEvents->where('type', 'click')->count(),
                'ordersPlaced' => $dayOrders->count(),
                'ordersPaid' => $dayOrders->where('payment_status', 'paid')->count(),
            ];
        }

        return $rows;
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
            ->take(200)
            ->values()
            ->all();
    }
}
