<?php

namespace Tests\Feature;

use Tests\TestCase;

class LegalPagesTest extends TestCase
{
    public function test_privacy_policy_is_publicly_available(): void
    {
        $this->get('/privacy')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Legal/Privacy'));
    }

    public function test_terms_are_publicly_available(): void
    {
        $this->get('/terms')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Legal/Terms'));
    }
}
