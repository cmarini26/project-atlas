<?php

namespace Database\Seeders;

use App\Models\Approval;
use App\Models\Campaign;
use App\Models\Catalog;
use App\Models\CatalogItem;
use App\Models\Channel;
use App\Models\ChannelCredentials;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\ContentAsset;
use App\Models\Decision;
use App\Models\DigitalTwin;
use App\Models\Execution;
use App\Models\ExecutionAttempt;
use App\Models\Fact;
use App\Models\Integration;
use App\Models\Knowledge;
use App\Models\Observation;
use App\Models\Opportunity;
use App\Models\Recommendation;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        // ── Superadmin ────────────────────────────────────────────────────────
        $admin = User::withoutGlobalScopes()->updateOrCreate(
            ['email' => 'admin@atlas.test'],
            [
                'name' => 'Atlas Admin',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
                'is_superadmin' => true,
            ]
        );

        // ── CBB Auctions owner ────────────────────────────────────────────────
        $user = User::withoutGlobalScopes()->updateOrCreate(
            ['email' => 'user@atlas.test'],
            [
                'name' => 'Atlas User',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
                'is_superadmin' => false,
            ]
        );

        $this->command->info('Admin:  admin@atlas.test / password  (Filament superadmin)');
        $this->command->info('Owner:  user@atlas.test  / password  (CBB Auctions owner)');

        // ── CBB Auctions ──────────────────────────────────────────────────────
        $this->seedCbbAuctions($user);

        // ── Luxe Motor Group ──────────────────────────────────────────────────
        $this->seedLuxeMotorGroup($admin);

        $this->command->info('CBB Auctions: full pipeline (opportunity → decision → campaign → execution).');
        $this->command->info('Luxe Motor Group: open opportunity only.');
        $this->command->newLine();
        $this->command->info('→ http://localhost:8000/admin');
    }

    private function seedCbbAuctions(User $user): Company
    {
        $company = Company::withoutGlobalScopes()->updateOrCreate(
            ['slug' => 'cbb-auctions'],
            [
                'name' => 'CBB Auctions',
                'industry' => 'auction',
                'website_url' => 'https://cbbauctions.com',
                'brand' => [
                    'colors' => ['primary' => '#1a1a2e', 'accent' => '#e94560'],
                    'voice' => 'authoritative, collector-focused',
                ],
                'settings' => ['timezone' => 'America/New_York'],
            ]
        );

        CompanyMembership::withoutGlobalScopes()->updateOrCreate(
            ['company_id' => $company->id, 'user_id' => $user->id],
            ['role' => 'owner']
        );

        DigitalTwin::withoutGlobalScopes()->updateOrCreate(
            ['company_id' => $company->id],
            [
                'status' => 'active',
                'health_score' => 82,
                'last_enriched_at' => now()->subHours(6),
            ]
        );

        $catalog = Catalog::withoutGlobalScopes()->updateOrCreate(
            ['company_id' => $company->id],
            ['name' => 'Comic Book Inventory', 'type' => 'inventory']
        );

        $integration = Integration::withoutGlobalScopes()->updateOrCreate(
            ['company_id' => $company->id, 'type' => 'website_crawl'],
            [
                'name' => 'Website Crawl',
                'status' => 'active',
                'config' => json_encode(['url' => 'https://cbbauctions.com']),
                'next_run_at' => now()->addDays(7),
                'last_successful_run_at' => now()->subHours(6),
            ]
        );

        $observation = Observation::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'integration_id' => $integration->id,
            'source_type' => 'crawl',
            'source_identifier' => 'https://cbbauctions.com',
            'raw_payload' => json_encode([
                'url' => 'https://cbbauctions.com',
                'bodyText' => 'CBB Auctions — the premier destination for certified comic book collecting. Weekly auctions of CGC-certified Silver and Bronze Age Marvel and DC books.',
            ]),
            'status' => 'processed',
            'observed_at' => now()->subHours(7),
            'processed_at' => now()->subHours(6),
        ]);

        $facts = [
            ['key' => 'business.name',                    'value' => 'CBB Auctions',                                              'data_type' => 'string',  'confidence' => 98],
            ['key' => 'business.industry',                'value' => 'comic book auctions and collectibles marketplace',           'data_type' => 'string',  'confidence' => 95],
            ['key' => 'catalog.active_items',             'value' => 847,                                                          'data_type' => 'integer', 'confidence' => 90],
            ['key' => 'catalog.ending_within_48h_count',  'value' => 23,                                                           'data_type' => 'integer', 'confidence' => 88],
            ['key' => 'marketing.days_since_last_campaign', 'value' => 9,                                                          'data_type' => 'integer', 'confidence' => 85],
            ['key' => 'audience.primary_segment',         'value' => 'CGC-certified Silver Age Marvel collectors aged 28–52',     'data_type' => 'string',  'confidence' => 80],
            ['key' => 'performance.avg_hammer_price',     'value' => 342,                                                          'data_type' => 'integer', 'confidence' => 75],
        ];

        foreach ($facts as $f) {
            Fact::withoutGlobalScopes()->create([
                'company_id' => $company->id,
                'observation_id' => $observation->id,
                'key' => $f['key'],
                'value' => json_encode($f['value']),
                'data_type' => $f['data_type'],
                'confidence' => $f['confidence'],
                'is_current' => true,
            ]);
        }

        Knowledge::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'type' => 'context',
            'subject' => 'business',
            'body' => 'CBB Auctions is a high-volume comic book auction house specializing in CGC-certified Silver and Bronze Age Marvel and DC books. Primary revenue comes from weekly auction events. Their collector audience is highly engaged and price-sensitive to certified grades.',
            'confidence' => 88,
            'is_active' => true,
        ]);

        Knowledge::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'type' => 'context',
            'subject' => 'catalog',
            'body' => 'Current inventory of 847 active items. 23 lots ending in 48 hours. Highest value items are Silver Age Marvel (early Spider-Man runs, X-Men). Average hammer price $342 over last 90 days.',
            'confidence' => 82,
            'is_active' => true,
        ]);

        $catalogItems = [];
        $itemsData = [
            ['title' => 'Amazing Spider-Man #1 CGC 7.5 (1963)',                  'price' => 12500.00, 'metadata' => ['grade' => '7.5', 'publisher' => 'Marvel', 'year' => 1963, 'certification' => 'CGC']],
            ['title' => 'X-Men #1 CGC 6.0 (1963)',                               'price' => 8750.00,  'metadata' => ['grade' => '6.0', 'publisher' => 'Marvel', 'year' => 1963, 'certification' => 'CGC']],
            ['title' => 'Fantastic Four #48 CGC 8.0 — Silver Surfer 1st App',    'price' => 4200.00,  'metadata' => ['grade' => '8.0', 'publisher' => 'Marvel', 'year' => 1966, 'certification' => 'CGC', 'key_issue' => true]],
            ['title' => 'Detective Comics #359 CGC 7.0 — 1st Batgirl',           'price' => 1850.00,  'metadata' => ['grade' => '7.0', 'publisher' => 'DC',     'year' => 1967, 'certification' => 'CGC', 'key_issue' => true]],
            ['title' => 'Incredible Hulk #181 CGC 9.0 — 1st Wolverine',          'price' => 9800.00,  'metadata' => ['grade' => '9.0', 'publisher' => 'Marvel', 'year' => 1974, 'certification' => 'CGC', 'key_issue' => true]],
        ];

        foreach ($itemsData as $item) {
            $catalogItems[] = CatalogItem::withoutGlobalScopes()->create([
                'company_id' => $company->id,
                'catalog_id' => $catalog->id,
                'title' => $item['title'],
                'price' => $item['price'],
                'metadata' => $item['metadata'],
                'status' => 'active',
                'promoted_at' => null,
            ]);
        }

        $emailChannel = Channel::withoutGlobalScopes()->updateOrCreate(
            ['company_id' => $company->id, 'type' => 'email'],
            ['name' => 'Email Newsletter', 'is_active' => true]
        );

        ChannelCredentials::withoutGlobalScopes()->updateOrCreate(
            ['company_id' => $company->id, 'channel_type' => 'email'],
            [
                'provider_type' => 'log',
                'credentials' => json_encode(['mode' => 'log', 'from' => 'auctions@cbbauctions.com']),
                'status' => 'active',
            ]
        );

        $opportunity = Opportunity::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'subject_type' => 'catalog_item',
            'subject_id' => $catalogItems[0]->id,
            'type' => 'featured_item',
            'title' => 'High-value Silver Age collection — no campaign in 9 days',
            'description' => 'ASM #1 CGC 7.5 and X-Men #1 CGC 6.0 have not been promoted in over a week. Auction closes in 72 hours. High-value lots with strong historical demand signal.',
            'relevance_score' => 88,
            'timing_score' => 82,
            'confidence_score' => 76,
            'urgency_score' => 79,
            'composite_score' => 82,
            'ai_detected' => false,
            'status' => 'selected',
            'expires_at' => now()->addDays(3),
            'detected_at' => now()->subHours(4),
        ]);

        $decision = Decision::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'opportunity_id' => $opportunity->id,
            'campaign_type' => 'featured_item',
            'channel_ids' => [$emailChannel->id],
            'rationale' => [
                'why_now' => 'Auction closes in 72 hours. Email sent within this window drives 3x registration rate vs. post-close outreach.',
                'why_this' => 'ASM #1 CGC 7.5 is the highest-value lot. Featuring it anchors perceived value and draws serious bidders.',
                'why_channel' => 'Email converts at 4.2% for high-value comic lots vs. 0.8% for social. Subscriber list skews toward $1K+ buyers.',
                'why_works' => 'Last Silver Age Marvel campaign drove a 24% lift in registered bidders vs. the 30-day average.',
                'expected_impact' => [
                    'summary' => '18% lift in auction registration from email campaign traffic',
                    'reach_estimate' => '3,800 subscribers — open rate 28% expected',
                    'engagement_signal' => 'Click-to-register rate estimated at 4.2% based on prior campaigns',
                    'confidence_basis' => 'Based on 6 comparable prior campaigns over 90 days',
                ],
            ],
            'expected_impact' => [
                'summary' => '18% lift in auction registration from email campaign traffic',
                'reach_estimate' => '3,800 subscribers — open rate 28% expected',
                'engagement_signal' => 'Click-to-register rate estimated at 4.2%',
                'confidence_basis' => 'Based on 6 comparable prior campaigns over 90 days',
            ],
            'confidence_score' => 78,
            'prompt_version' => '1.0',
            'status' => 'recommended',
            'decided_at' => now()->subHours(3),
        ]);

        $campaign = Campaign::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'decision_id' => $decision->id,
            'campaign_type' => 'featured_item',
            'title' => "Conversion — The rarest Silver Age Marvel collection we've seen in two years",
            'target_audience' => 'CGC-certified Silver Age Marvel collectors aged 28–52 with a bid history of $500+',
            'positioning' => "This week's auction features the rarest Silver Age Marvel collection we've seen in two years — bid now before it's gone forever",
            'call_to_action' => 'Register and place your opening bid before Sunday 10pm ET',
            'blueprint' => [
                'version' => '1.0',
                'goal' => 'conversion',
                'audience' => 'CGC-certified Silver Age Marvel collectors aged 28–52 with a bid history of $500+',
                'core_message' => "This week's auction features the rarest Silver Age Marvel collection we've seen in two years — bid now before it's gone forever",
                'supporting_points' => [
                    'ASM #1 CGC 7.5 leads a lineup of 40 Silver Age books all graded 7.0 or higher',
                    'Starting bids set at 30% below recent Heritage Auctions comparables',
                    'Auction closes Sunday at 10pm ET — no extensions, no second chances',
                ],
                'call_to_action' => 'Register and place your opening bid before Sunday 10pm ET',
                'offer' => 'Free shipping on all winning bids over $500',
                'tone' => [
                    'voice' => 'authoritative',
                    'modifier' => 'urgent',
                    'avoid' => ['cheap', 'discount', 'sale', 'junk'],
                ],
                'landing_page' => null,
                'success_metrics' => [
                    'primary_metric' => 'Auction registration rate from campaign email traffic',
                    'secondary_metrics' => ['Bid count per featured item', 'Avg hammer price vs. comparable'],
                    'baseline' => 'Prior 30-day average: 12% registration rate from email',
                    'timeframe' => '72 hours from send to auction close',
                ],
                'channel_strategy' => [
                    'email' => [
                        'format' => 'single send — pre-auction featured lot preview',
                        'angle' => 'Exclusive first look for serious collectors',
                        'constraints' => ['Subject line under 60 chars', 'Single primary CTA button'],
                        'priority' => 1,
                    ],
                ],
            ],
            'blueprint_version' => '1.0',
            'prompt_version' => '1.0',
            'expected_asset_count' => 1,
            'generated_asset_count' => 1,
            'status' => 'published',
            'completed_at' => now()->subHour(),
        ]);

        $asset = ContentAsset::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'campaign_id' => $campaign->id,
            'channel_id' => $emailChannel->id,
            'type' => 'email',
            'title' => "The rarest Silver Age Marvel collection we've seen in two years — ends Sunday",
            'body' => "Hi [first_name],\n\nWe don't send emails like this often. But this Sunday's auction deserves your attention.\n\nLeading the lineup: Amazing Spider-Man #1 CGC 7.5 (1963). A 7.5 of this title, in this condition, at this price — it doesn't come around often.\n\nJoining it: X-Men #1 CGC 6.0, Fantastic Four #48 CGC 8.0 (first Silver Surfer appearance), and 37 more Silver Age books all graded 7.0 or higher.\n\nStarting bids are set at 30% below recent Heritage comparables.\n\nAuction closes Sunday at 10pm ET. No extensions. No buybacks.\n\n[REGISTER AND BID NOW]\n\nFree shipping on all winning bids over \$500 this auction.\n\n— The CBB Auctions Team",
            'metadata' => [
                'subject_line' => "The rarest Silver Age Marvel collection we've seen in two years — ends Sunday",
                'preview_text' => 'ASM #1 CGC 7.5 leads 40 Silver Age books. Ends Sunday 10pm ET.',
                'from_name' => 'CBB Auctions',
                'from_email' => 'auctions@cbbauctions.com',
            ],
            'prompt_name' => 'email-content',
            'prompt_version' => '1.0',
            'status' => 'published',
            'published_at' => now()->subDays(2),
        ]);

        $recommendation = Recommendation::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'decision_id' => $decision->id,
            'campaign_id' => $campaign->id,
            'campaign_type' => 'featured_item',
            'rationale_display' => [
                'why_now' => 'Auction closes in 72 hours. Email sent within this window drives 3x registration rate.',
                'why_this' => 'ASM #1 CGC 7.5 is the highest-value lot. Featuring it anchors perceived value.',
                'why_channel' => 'Email converts at 4.2% for high-value comic lots vs. 0.8% for social.',
                'why_works' => 'Last Silver Age Marvel campaign drove a 24% lift in registered bidders.',
                'expected_impact' => [
                    'summary' => '18% lift in auction registration from email campaign traffic',
                    'reach_estimate' => '3,800 subscribers — open rate 28% expected',
                    'engagement_signal' => 'Click-to-register rate estimated at 4.2%',
                    'confidence_basis' => 'Based on 6 comparable prior campaigns over 90 days',
                ],
            ],
            'expected_impact' => ['summary' => '18% lift in auction registration from email campaign traffic'],
            'status' => 'approved',
        ]);

        Approval::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'approvable_type' => Recommendation::class,
            'approvable_id' => $recommendation->id,
            'user_id' => $user->id,
            'action' => 'approved',
            'acted_at' => now()->subDays(2)->addHours(1),
        ]);

        $execution = Execution::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'campaign_id' => $campaign->id,
            'content_asset_id' => $asset->id,
            'channel_id' => $emailChannel->id,
            'status' => 'completed',
            'idempotency_key' => Str::ulid()->toString(),
            'attempts' => 1,
            'executed_at' => now()->subDays(2)->addHours(2),
            'completed_at' => now()->subDays(2)->addHours(2),
            'result' => [
                'platform_id' => 'log-'.Str::ulid()->toString(),
                'url' => null,
                'published_at' => now()->subDays(2)->addHours(2)->toIso8601String(),
                'metadata' => ['publisher' => 'log'],
            ],
        ]);

        ExecutionAttempt::withoutGlobalScopes()->create([
            'execution_id' => $execution->id,
            'attempt_number' => 1,
            'status' => 'completed',
            'error' => null,
            'response' => ['platform_id' => $execution->result['platform_id']],
            'attempted_at' => now()->subDays(2)->addHours(2),
        ]);

        return $company;
    }

    private function seedLuxeMotorGroup(User $user): Company
    {
        $company = Company::withoutGlobalScopes()->updateOrCreate(
            ['slug' => 'luxe-motor-group'],
            [
                'name' => 'Luxe Motor Group',
                'industry' => 'automotive',
                'website_url' => 'https://luxemotorgroup.com',
                'brand' => [
                    'colors' => ['primary' => '#0d0d0d', 'accent' => '#c9a84c'],
                    'voice' => 'sophisticated, aspirational',
                ],
                'settings' => ['timezone' => 'America/Los_Angeles'],
            ]
        );

        CompanyMembership::withoutGlobalScopes()->updateOrCreate(
            ['company_id' => $company->id, 'user_id' => $user->id],
            ['role' => 'owner']
        );

        DigitalTwin::withoutGlobalScopes()->updateOrCreate(
            ['company_id' => $company->id],
            [
                'status' => 'active',
                'health_score' => 71,
                'last_enriched_at' => now()->subDay(),
            ]
        );

        $catalog = Catalog::withoutGlobalScopes()->updateOrCreate(
            ['company_id' => $company->id],
            ['name' => 'Vehicle Inventory', 'type' => 'inventory']
        );

        $emailChannel = Channel::withoutGlobalScopes()->updateOrCreate(
            ['company_id' => $company->id, 'type' => 'email'],
            ['name' => 'Customer Email', 'is_active' => true]
        );

        $vehicles = [
            ['title' => '2023 Lamborghini Huracán EVO Spyder', 'price' => 289000.00, 'metadata' => ['year' => 2023, 'miles' => 4200, 'color' => 'Arancio Borealis']],
            ['title' => '2022 Ferrari 812 GTS',                 'price' => 465000.00, 'metadata' => ['year' => 2022, 'miles' => 1800, 'color' => 'Rosso Corsa']],
            ['title' => '2021 McLaren 765LT Spider',            'price' => 378000.00, 'metadata' => ['year' => 2021, 'miles' => 3100, 'color' => 'Papaya Spark']],
        ];

        $catalogItems = [];
        foreach ($vehicles as $v) {
            $catalogItems[] = CatalogItem::withoutGlobalScopes()->create([
                'company_id' => $company->id,
                'catalog_id' => $catalog->id,
                'title' => $v['title'],
                'price' => $v['price'],
                'metadata' => $v['metadata'],
                'status' => 'active',
            ]);
        }

        Opportunity::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'subject_type' => 'catalog_item',
            'subject_id' => $catalogItems[0]->id,
            'type' => 'featured_item',
            'title' => '3 exotic vehicles — no featured campaign in 12 days',
            'description' => 'Huracán EVO Spyder, 812 GTS, and 765LT Spider have not been individually featured. Inventory is aging without exposure.',
            'relevance_score' => 79,
            'timing_score' => 71,
            'confidence_score' => 68,
            'urgency_score' => 63,
            'composite_score' => 71,
            'ai_detected' => false,
            'status' => 'open',
            'expires_at' => now()->addDays(7),
            'detected_at' => now()->subHours(2),
        ]);

        return $company;
    }
}
