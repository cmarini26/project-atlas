<?php

namespace Tests\Unit\Onboarding;

use App\Domain\Onboarding\AssetDetailRequirements;
use Tests\TestCase;

class AssetDetailRequirementsTest extends TestCase
{
    public function test_website_requires_url_and_platform(): void
    {
        $this->assertFalse(AssetDetailRequirements::isSatisfied('website', null, []));
        $this->assertFalse(AssetDetailRequirements::isSatisfied('website', 'https://acme.com', []));
        $this->assertFalse(AssetDetailRequirements::isSatisfied('website', 'https://acme.com', ['platform' => '']));
        $this->assertTrue(AssetDetailRequirements::isSatisfied('website', 'https://acme.com', ['platform' => 'wordpress']));
    }

    public function test_url_only_types_require_a_handle_or_url(): void
    {
        foreach (['instagram', 'facebook', 'linkedin', 'youtube', 'x', 'google_business_profile'] as $type) {
            $this->assertFalse(AssetDetailRequirements::isSatisfied($type, null, []), "{$type} should require a handle_or_url");
            $this->assertFalse(AssetDetailRequirements::isSatisfied($type, '', []), "{$type} should treat an empty string as missing");
            $this->assertTrue(AssetDetailRequirements::isSatisfied($type, 'https://example.com/acme', []), "{$type} should be satisfied once a URL is set");
        }
    }

    public function test_optional_types_never_require_anything(): void
    {
        foreach (['email', 'events', 'print', 'tiktok', 'other'] as $type) {
            $this->assertTrue(AssetDetailRequirements::isSatisfied($type, null, []), "{$type} should never require details");
        }
    }
}
