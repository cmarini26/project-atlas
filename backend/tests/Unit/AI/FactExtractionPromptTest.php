<?php

namespace Tests\Unit\AI;

use App\AI\Prompts\FactExtractionPrompt;
use Tests\TestCase;

class FactExtractionPromptTest extends TestCase
{
    public function test_system_prompt_is_non_empty_string(): void
    {
        $prompt = new FactExtractionPrompt(
            pageUrl: 'https://example.com',
            pageTitle: 'Home',
            bodyText: 'Some content',
        );

        $this->assertNotEmpty($prompt->system());
        $this->assertIsString($prompt->system());
    }

    public function test_user_prompt_includes_url_title_and_content(): void
    {
        $prompt = new FactExtractionPrompt(
            pageUrl: 'https://example.com',
            pageTitle: 'Home Page',
            bodyText: 'We sell comic books.',
        );

        $user = $prompt->user();

        $this->assertStringContainsString('https://example.com', $user);
        $this->assertStringContainsString('Home Page', $user);
        $this->assertStringContainsString('We sell comic books.', $user);
    }

    public function test_schema_defines_facts_array(): void
    {
        $prompt = new FactExtractionPrompt(
            pageUrl: 'https://example.com',
            pageTitle: 'Home',
            bodyText: 'content',
        );

        $schema = $prompt->schema();

        $this->assertIsArray($schema);
        $this->assertArrayHasKey('properties', $schema);
        $this->assertArrayHasKey('facts', $schema['properties']);
    }

    public function test_version_is_set(): void
    {
        $prompt = new FactExtractionPrompt(
            pageUrl: 'https://example.com',
            pageTitle: 'Home',
            bodyText: 'content',
        );

        $this->assertSame('1.0', $prompt->version());
    }

    public function test_temperature_is_low_for_determinism(): void
    {
        $prompt = new FactExtractionPrompt(
            pageUrl: 'https://example.com',
            pageTitle: 'Home',
            bodyText: 'content',
        );

        $this->assertLessThanOrEqual(0.2, $prompt->temperature());
    }
}
