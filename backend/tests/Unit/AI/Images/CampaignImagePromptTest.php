<?php

namespace Tests\Unit\AI\Images;

use App\AI\Images\CampaignImagePrompt;
use App\Domain\BusinessBrain\BusinessBrain;
use App\Models\CampaignBrief;
use App\Models\Company;
use App\Models\DigitalTwin;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

class CampaignImagePromptTest extends TestCase
{
    private function brain(Company $company): BusinessBrain
    {
        return new BusinessBrain(
            company: $company,
            twin: new DigitalTwin(),
            activeFacts: new Collection(),
            activeKnowledge: new Collection(),
            recentObservations: new Collection(),
            catalog: null,
            featuredItems: new Collection(),
            recentCampaigns: new Collection(),
        );
    }

    public function test_it_grounds_the_prompt_in_company_identity_and_not_the_raw_objective(): void
    {
        $company = new Company(['name' => 'Northwind Motors', 'industry' => 'exotic car dealership']);
        $company->brand = ['voice' => 'bold and precise'];

        $brief = new CampaignBrief([
            'goal' => 'conversion',
            'objective' => 'Push the weekend test-drive event and get 20 bookings by Friday.',
            'audience' => 'Local enthusiasts within 50 miles.',
        ]);

        $prompt = CampaignImagePrompt::forBrief($brief, $this->brain($company));

        $this->assertStringContainsString('Northwind Motors', $prompt);
        $this->assertStringContainsString('exotic car dealership', $prompt);
        $this->assertStringContainsString('bold and precise', $prompt);
        $this->assertStringContainsString('Do not include any text', $prompt);
        $this->assertStringContainsString('Editorial marketing photograph', $prompt);
        // The KPI tail and hard numbers are stripped — not a raw passthrough.
        $this->assertStringNotContainsString('20 bookings', $prompt);
        $this->assertStringNotContainsString('by Friday', $prompt);
    }

    public function test_it_produces_a_usable_prompt_from_a_bare_brain(): void
    {
        $company = new Company(['name' => 'Corner Cafe']);
        $brief = new CampaignBrief(['goal' => 'awareness', 'objective' => 'Introduce our new seasonal menu.']);

        $prompt = CampaignImagePrompt::forBrief($brief, $this->brain($company));

        $this->assertStringContainsString('Corner Cafe', $prompt);
        $this->assertStringContainsString('small business', $prompt);
        $this->assertNotEmpty(trim($prompt));
    }
}
