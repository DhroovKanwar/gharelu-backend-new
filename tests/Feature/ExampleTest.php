<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_the_api_root_shows_no_page_and_does_not_name_the_framework(): void
    {
        $response = $this->get('/');

        $response->assertNotFound();
        $this->assertStringNotContainsStringIgnoringCase('laravel', (string) $response->getContent());
    }
}
