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

    public function test_optional_types_never_require_anything(): void
    {
        // Workstream C.1 (UI rethink) — only Website requires details up
        // front in onboarding; every other declarable type (including
        // Instagram/Facebook/LinkedIn/YouTube/X/Google Business, which used
        // to require a URL here) is declared now and detailed later from
        // Settings, since Discovery can't act on those details during
        // onboarding anyway.
        foreach (['instagram', 'facebook', 'linkedin', 'youtube', 'x', 'google_business_profile', 'email', 'events', 'print', 'tiktok', 'other'] as $type) {
            $this->assertTrue(AssetDetailRequirements::isSatisfied($type, null, []), "{$type} should never require details");
        }
    }
}
