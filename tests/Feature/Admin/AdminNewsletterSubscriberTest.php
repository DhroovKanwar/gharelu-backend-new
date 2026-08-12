<?php

namespace Tests\Feature\Admin;

use App\Models\NewsletterSubscriber;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAdminUsers;
use Tests\TestCase;

class AdminNewsletterSubscriberTest extends TestCase
{
    use RefreshDatabase, CreatesAdminUsers;

    public function test_manager_can_list_subscribers(): void
    {
        [, $token] = $this->actingAsAdmin('manager');
        NewsletterSubscriber::create(['email' => 'a@example.com']);
        NewsletterSubscriber::create(['email' => 'b@example.com']);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/admin/newsletter-subscribers');

        $response->assertOk();
        $this->assertCount(2, $response->json('data'));
    }

    public function test_manager_can_search_subscribers_by_email(): void
    {
        [, $token] = $this->actingAsAdmin('manager');
        NewsletterSubscriber::create(['email' => 'findme@example.com']);
        NewsletterSubscriber::create(['email' => 'other@example.com']);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/admin/newsletter-subscribers?search=findme');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
    }

    public function test_staff_cannot_access_newsletter_subscribers(): void
    {
        [, $token] = $this->actingAsAdmin('staff');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/admin/newsletter-subscribers')
            ->assertStatus(403);
    }

    public function test_customer_cannot_access_newsletter_subscribers(): void
    {
        $customer = User::factory()->create();
        $token = $this->tokenFor($customer);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/admin/newsletter-subscribers')
            ->assertStatus(403);
    }
}
