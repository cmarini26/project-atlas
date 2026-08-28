<?php

namespace Tests\Feature\Onboarding;

use App\Domain\Onboarding\OnboardingAssetTypes;
use Tests\TestCase;

/**
 * The onboarding asset-type metadata is duplicated across PHP
 * ({@see OnboardingAssetTypes}) and TS (resources/js/lib/onboardingAssets.ts)
 * because both render it. This test fails the moment the two drift.
 */
class OnboardingAssetTypeParityTest extends TestCase
{
    /**
     * @return array<string, array{requires_details: bool, kind: string, summary: string}>
     */
    private function parseTsDefinitions(): array
    {
        $source = (string) file_get_contents(base_path('resources/js/lib/onboardingAssets.ts'));

        // Isolate the ONBOARDING_ASSET_TYPES array literal.
        $start = strpos($source, 'export const ONBOARDING_ASSET_TYPES');
        $this->assertNotFalse($start, 'ONBOARDING_ASSET_TYPES not found in onboardingAssets.ts');
        $block = substr($source, $start);
        $block = substr($block, 0, (int) strpos($block, "\n]"));

        preg_match_all(
            '/\{\s*type:\s*\'(?<type>[a-z_]+)\',\s*label:\s*\'[^\']*\',\s*requiresDetails:\s*(?<details>true|false),\s*integrationRequirement:\s*\{\s*kind:\s*\'(?<kind>[a-z]+)\',\s*summary:\s*\'(?<summary>[^\']*)\'\s*\}\s*\}/',
            $block,
            $matches,
            PREG_SET_ORDER,
        );

        $parsed = [];
        foreach ($matches as $m) {
            $parsed[$m['type']] = [
                'requires_details' => $m['details'] === 'true',
                'kind' => $m['kind'],
                'summary' => $m['summary'],
            ];
        }

        return $parsed;
    }

    public function test_ts_and_php_cover_an_identical_set_of_types(): void
    {
        $ts = array_keys($this->parseTsDefinitions());
        $php = OnboardingAssetTypes::types();

        sort($ts);
        sort($php);

        $this->assertSame($php, $ts);
    }

    public function test_ts_and_php_agree_on_requirement_kind_details_flag_and_summary(): void
    {
        $ts = $this->parseTsDefinitions();

        foreach (OnboardingAssetTypes::all() as $definition) {
            $type = $definition['type'];
            $this->assertArrayHasKey($type, $ts, "TS is missing asset type [{$type}]");

            $this->assertSame(
                $definition['integration_requirement'],
                $ts[$type]['kind'],
                "requirement kind mismatch for [{$type}]",
            );
            $this->assertSame(
                $definition['requires_details'],
                $ts[$type]['requires_details'],
                "requiresDetails mismatch for [{$type}]",
            );
            $this->assertSame(
                $definition['integration_requirement_summary'],
                $ts[$type]['summary'],
                "requirement summary mismatch for [{$type}]",
            );
        }
    }

    public function test_every_requirement_kind_is_valid(): void
    {
        foreach (OnboardingAssetTypes::all() as $definition) {
            $this->assertContains(
                $definition['integration_requirement'],
                OnboardingAssetTypes::REQUIREMENT_KINDS,
            );
        }
    }
}
