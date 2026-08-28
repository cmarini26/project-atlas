<?php

namespace App\AI\Images;

use App\Domain\BusinessBrain\BusinessBrain;
use App\Models\CampaignBrief;
use App\Models\Fact;
use Illuminate\Support\Str;

/**
 * Builds the text prompt handed to the ImageProvider for a campaign.
 *
 * Deliberately does NOT pass the customer's raw objective through: that text
 * is written to persuade a reader, not to describe a photograph. Instead it
 * distils the objective into a short visual concept and grounds it in the
 * Business Brain — company name, industry, brand voice — so generated imagery
 * looks like it belongs to this business.
 */
final class CampaignImagePrompt
{
    public static function forBrief(CampaignBrief $brief, BusinessBrain $brain): string
    {
        $company = $brain->company;

        $industry = self::firstNonEmpty([
            is_string($company->industry ?? null) ? $company->industry : null,
            self::factValue($brain, 'industry'),
            self::factValue($brain, 'business_type'),
        ]) ?? 'small business';

        $voice = self::brandVoice($company) ?? 'confident and professional';

        $concept = self::visualConcept($brief);

        $audience = is_string($brief->audience) && trim($brief->audience) !== ''
            ? ' Audience it should resonate with: '.self::clip($brief->audience, 160).'.'
            : '';

        return implode(' ', array_filter([
            sprintf(
                'Editorial marketing photograph for %s, a %s.',
                self::clip($company->name, 80),
                self::clip($industry, 80),
            ),
            sprintf('Visual concept: %s.', $concept),
            sprintf('Mood and tone: %s.', self::clip($voice, 120)),
            trim($audience),
            'Style: natural lighting, shallow depth of field, realistic, high quality, uncluttered composition with space for a headline.',
            'Do not include any text, words, letters, logos, watermarks, or user-interface elements.',
        ]));
    }

    private static function visualConcept(CampaignBrief $brief): string
    {
        $objective = trim((string) preg_replace('/\s+/', ' ', $brief->objective));
        $firstSentence = preg_split('/(?<=[.!?])\s+/', $objective)[0] ?? $objective;

        // Drop the parts that describe a business outcome rather than a scene:
        // KPI tails ("… and get 20 bookings by Friday", "… to increase signups")
        // and bare numbers/percentages the camera cannot show.
        $subject = (string) preg_replace(
            [
                '/\b(and|so as to|so that|in order to|to)\s+(get|drive|increase|boost|grow|hit|reach|generate|convert|book|sell)\b.*$/i',
                '/\b\d[\d,.]*\s*%?/',
            ],
            '',
            $firstSentence,
        );

        $subject = self::clip(trim(rtrim($subject, ' .!?,-')), 140);

        if ($subject === '') {
            $subject = 'the offer at the heart of this campaign';
        }

        $goalFraming = match ($brief->goal) {
            'awareness' => 'an inviting, brand-defining scene evoking',
            're_engagement' => 'a warm, familiar scene that reconnects past customers with',
            default => 'an aspirational lifestyle scene built around',
        };

        return $goalFraming.' '.lcfirst($subject);
    }

    private static function brandVoice(object $company): ?string
    {
        $raw = $company->brand ?? null;
        $brand = is_string($raw) ? json_decode($raw, true) : $raw;

        if (is_array($brand) && isset($brand['voice']) && is_string($brand['voice']) && trim($brand['voice']) !== '') {
            return $brand['voice'];
        }

        return null;
    }

    private static function factValue(BusinessBrain $brain, string $key): ?string
    {
        $fact = $brain->activeFacts->first(
            fn (Fact $f): bool => Str::contains(Str::lower($f->key), $key),
        );

        if ($fact === null) {
            return null;
        }

        $value = is_array($fact->value) ? ($fact->value['value'] ?? reset($fact->value)) : $fact->value;

        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    /**
     * @param  list<string|null>  $candidates
     */
    private static function firstNonEmpty(array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return null;
    }

    private static function clip(string $value, int $max): string
    {
        $value = trim($value);

        return mb_strlen($value) > $max ? rtrim(mb_substr($value, 0, $max)).'…' : $value;
    }
}
