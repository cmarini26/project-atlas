<?php

namespace Tests\Feature;

use Tests\TestCase;

class ApplicationBootTest extends TestCase
{
    public function test_application_boots_successfully(): void
    {
        $response = $this->get('/');

        // Root redirects to /login
        $response->assertRedirect('/login');
    }

    public function test_application_container_resolves_core_bindings(): void
    {
        $this->assertNotNull(app('db'));
        $this->assertNotNull(app('cache'));
        $this->assertNotNull(app('queue'));
        $this->assertNotNull(app('config'));
    }

    public function test_application_environment_is_testing(): void
    {
        $this->assertEquals('testing', app()->environment());
    }
}
