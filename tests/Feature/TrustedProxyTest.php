<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Middleware\TrustProxies;
use Tests\TestCase;

/**
 * Rate limits are per visitor IP. Behind Cloudflare that IP only arrives in X-Forwarded-For, which is
 * read only when TRUSTED_PROXIES is set.
 */
class TrustedProxyTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        TrustProxies::flushState();

        parent::tearDown();
    }

    private function subscribe(int $n, string $forwardedFor)
    {
        return $this->withHeader('X-Forwarded-For', $forwardedFor)
            ->postJson('/api/v1/newsletter/subscribe', ['email' => "fan{$n}@example.com"]);
    }

    public function test_without_trusted_proxies_all_visitors_share_one_limit(): void
    {
        foreach (range(1, 5) as $n) {
            $this->subscribe($n, '10.0.0.1')->assertCreated();
        }

        // A different visitor behind the same proxy is counted against the same limit.
        $this->subscribe(6, '10.0.0.2')->assertTooManyRequests();
    }

    public function test_with_trusted_proxies_each_visitor_has_their_own_limit(): void
    {
        TrustProxies::at('*');

        foreach (range(1, 5) as $n) {
            $this->subscribe($n, '10.0.0.1')->assertCreated();
        }

        $this->subscribe(6, '10.0.0.1')->assertTooManyRequests();
        $this->subscribe(7, '10.0.0.2')->assertCreated();
    }
}
