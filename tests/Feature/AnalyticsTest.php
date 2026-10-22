<?php

namespace Tests\Feature;

use App\Models\AnalyticsEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAdminUsers;
use Tests\TestCase;

class AnalyticsTest extends TestCase
{
    use RefreshDatabase, CreatesAdminUsers;

    private function track(string $visitor, array $events)
    {
        return $this->postJson('/api/v1/track', ['visitor_id' => $visitor, 'events' => $events]);
    }

    public function test_it_stores_events_and_strips_query_strings_from_the_path(): void
    {
        $this->track('abcd1234-visitor', [
            ['type' => 'pageview', 'label' => '/order-success', 'path' => '/order-success?order=GB123&phone=9999999999'],
            ['type' => 'click', 'label' => 'Add to cart', 'path' => '/catalogue'],
        ])->assertNoContent();

        $this->assertDatabaseCount('analytics_events', 2);
        $this->assertSame('/order-success', AnalyticsEvent::where('type', 'pageview')->value('path'));
    }

    public function test_it_rejects_malformed_payloads(): void
    {
        $this->track('x', [['type' => 'pageview', 'label' => '/', 'path' => '/']])->assertStatus(422);
        $this->track('abcd1234-visitor', [['type' => 'hack', 'label' => '/', 'path' => '/']])->assertStatus(422);
        $this->track('abcd1234-visitor', [])->assertStatus(422);
        $this->track('bad id with spaces', [['type' => 'click', 'label' => 'a', 'path' => '/']])->assertStatus(422);
        $this->assertDatabaseCount('analytics_events', 0);
    }

    public function test_admin_sees_unique_visitors_clicks_and_orders_for_the_day(): void
    {
        $this->track('aaaaaaaa-one', [
            ['type' => 'pageview', 'label' => '/', 'path' => '/'],
            ['type' => 'pageview', 'label' => '/catalogue', 'path' => '/catalogue'],
            ['type' => 'click', 'label' => 'Add to cart', 'path' => '/catalogue'],
        ])->assertNoContent();
        $this->track('bbbbbbbb-two', [
            ['type' => 'pageview', 'label' => '/', 'path' => '/'],
            ['type' => 'click', 'label' => 'Add to cart', 'path' => '/'],
        ])->assertNoContent();

        [, $token] = $this->actingAsAdmin('manager');

        $response = $this->withToken($token)->getJson('/api/v1/admin/analytics')->assertOk();

        $response->assertJsonPath('data.visitors', 2)
            ->assertJsonPath('data.pageviews', 3)
            ->assertJsonPath('data.clicks', 2)
            ->assertJsonPath('data.orders.placed', 0)
            ->assertJsonPath('data.topClicks.0.label', 'Add to cart')
            ->assertJsonPath('data.topClicks.0.clicks', 2)
            ->assertJsonPath('data.topClicks.0.visitors', 2)
            ->assertJsonPath('data.topPages.0.label', '/')
            ->assertJsonCount(2, 'data.visitorActivity');
    }

    public function test_admin_can_query_a_date_range_with_a_daily_breakdown(): void
    {
        $this->track('aaaaaaaa-one', [['type' => 'pageview', 'label' => '/', 'path' => '/']])->assertNoContent();

        [, $token] = $this->actingAsAdmin('manager');
        $today = now('Asia/Kolkata')->toDateString();
        $weekAgo = now('Asia/Kolkata')->subDays(6)->toDateString();

        $response = $this->withToken($token)
            ->getJson("/api/v1/admin/analytics?from={$weekAgo}&to={$today}")
            ->assertOk()
            ->assertJsonPath('data.from', $weekAgo)
            ->assertJsonPath('data.to', $today)
            ->assertJsonPath('data.visitors', 1)
            ->assertJsonCount(7, 'data.daily')
            ->assertJsonPath('data.daily.6.date', $today)
            ->assertJsonPath('data.daily.6.pageviews', 1)
            ->assertJsonPath('data.daily.0.pageviews', 0);
    }

    public function test_date_range_is_validated(): void
    {
        [, $token] = $this->actingAsAdmin('manager');

        $this->withToken($token)->getJson('/api/v1/admin/analytics?from=2026-10-10&to=2026-10-01')->assertUnprocessable();
        $this->withToken($token)->getJson('/api/v1/admin/analytics?from=2026-01-01&to=2026-10-01')->assertUnprocessable();
    }

    public function test_other_days_are_excluded_and_staff_are_blocked(): void
    {
        AnalyticsEvent::create([
            'visitor_id' => 'cccccccc-old', 'type' => 'pageview', 'label' => '/', 'path' => '/',
            'created_at' => now()->subDays(3),
        ]);

        [, $manager] = $this->actingAsAdmin('manager');
        $this->withToken($manager)->getJson('/api/v1/admin/analytics')->assertOk()->assertJsonPath('data.visitors', 0);

        [, $staff] = $this->actingAsAdmin('staff');
        $this->app['auth']->forgetGuards();
        $this->withToken($staff)->getJson('/api/v1/admin/analytics')->assertForbidden();
    }

    public function test_analytics_requires_authentication(): void
    {
        $this->getJson('/api/v1/admin/analytics')->assertUnauthorized();
    }

    public function test_prune_removes_only_events_older_than_the_retention_period(): void
    {
        $row = fn (string $when) => ['visitor_id' => 'abcd1234-visitor', 'type' => 'pageview', 'label' => '/', 'path' => '/', 'created_at' => $when];
        AnalyticsEvent::insert([$row(now()->subDays(120)), $row(now()->subDays(91)), $row(now()->subDays(10)), $row(now())]);

        $this->artisan('analytics:prune')->assertSuccessful();

        $this->assertDatabaseCount('analytics_events', 2);

        $this->artisan('analytics:prune', ['--days' => 5])->assertSuccessful();

        $this->assertDatabaseCount('analytics_events', 1);
    }
}
