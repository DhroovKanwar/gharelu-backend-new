<?php

namespace Tests\Feature;

use App\Models\Enquiry;
use App\Models\NewsletterSubscriber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeadTest extends TestCase
{
    use RefreshDatabase;

    public function test_contact_form_is_saved_as_a_new_enquiry(): void
    {
        $this->postJson('/api/v1/enquiries', [
            'name' => 'Priya Sharma',
            'email' => 'priya@example.com',
            'phone' => '+91 98765 43210',
            'message' => 'Do you make eggless wedding cakes?',
        ])->assertCreated();

        $this->assertDatabaseHas('enquiries', [
            'name' => 'Priya Sharma',
            'email' => 'priya@example.com',
            'phone' => '9876543210',
            'status' => 'new',
        ]);
    }

    public function test_enquiry_is_validated_and_html_is_stripped(): void
    {
        $this->postJson('/api/v1/enquiries', ['name' => 'A', 'email' => 'nope', 'message' => 'short'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'message']);

        $this->postJson('/api/v1/enquiries', [
            'name' => '<b>Priya</b>', 'email' => 'p@example.com', 'message' => 'Hello <script>x</script> there, a question.',
        ])->assertCreated();

        $this->assertSame('Priya', Enquiry::first()->name);
        $this->assertStringNotContainsString('<script>', Enquiry::first()->message);
    }

    public function test_newsletter_signup_is_saved_once_per_email(): void
    {
        $this->postJson('/api/v1/newsletter/subscribe', ['email' => 'Fan@Example.com'])->assertCreated();
        $this->postJson('/api/v1/newsletter/subscribe', ['email' => 'fan@example.com'])->assertCreated();
        $this->postJson('/api/v1/newsletter/subscribe', ['email' => 'bad'])->assertUnprocessable();

        $this->assertSame(1, NewsletterSubscriber::count());
        $this->assertSame('fan@example.com', NewsletterSubscriber::first()->email);
    }

    public function test_the_forms_are_rate_limited(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/newsletter/subscribe', ['email' => "u{$i}@example.com"])->assertCreated();
        }

        $this->postJson('/api/v1/newsletter/subscribe', ['email' => 'u9@example.com'])->assertTooManyRequests();
    }
}
