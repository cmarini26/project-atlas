<?php

namespace App\Services\Learning;

use App\Models\Knowledge;
use App\Models\Learning;

class KnowledgeMutator
{
    private const int DEFAULT_CONFIDENCE = 70;

    private const int SAFETY_CONFIDENCE = 85;

    private const int TTL_DAYS = 90;

    /**
     * Apply Knowledge mutations for a Learning signal.
     * Returns an array of effect descriptors for the LearningApplication audit trail.
     *
     * @return list<array<string, mixed>>
     */
    public function mutate(Learning $learning): array
    {
        return match ($learning->signal) {
            'channel_outperformed' => $this->channelKnowledge($learning, 'preferred'),
            'channel_underperformed' => $this->channelKnowledge($learning, 'weak'),
            'email_deliverability_issue' => $this->emailHealthKnowledge($learning),
            'high_unsubscribe_rate' => $this->emailListQualityKnowledge($learning),
            'campaign_type_succeeded' => $this->campaignEffectivenessKnowledge($learning, 'effective'),
            'campaign_type_underperformed' => $this->campaignEffectivenessKnowledge($learning, 'ineffective'),
            'content_angle_engaged' => $this->contentAngleKnowledge($learning),
            'optimal_timing_signal' => $this->timingKnowledge($learning),
            'recommendation_approved' => $this->recommendationPreferenceKnowledge($learning, 'positive'),
            'recommendation_rejected' => $this->recommendationPreferenceKnowledge($learning, 'negative'),
            'recommendation_edited_and_approved' => $this->editedApprovalKnowledge($learning),
            default => [],
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function channelKnowledge(Learning $learning, string $disposition): array
    {
        /** @var array<string, mixed> $value */
        $value = $learning->value ?? [];
        $channel = (string) ($value['channel'] ?? '');

        if ($channel === '') {
            return [];
        }

        $subject = "channel.{$channel}.{$disposition}";
        $body = match ($disposition) {
            'preferred' => "The {$channel} channel consistently outperforms other channels for this company.",
            default => "The {$channel} channel consistently underperforms compared to other channels for this company.",
        };

        return $this->supersedeKnowledge($learning, $subject, $body, self::DEFAULT_CONFIDENCE);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function emailHealthKnowledge(Learning $learning): array
    {
        /** @var array<string, mixed> $value */
        $value = $learning->value ?? [];
        $hardBounces = (int) ($value['hard_bounces'] ?? 0);
        $spamRate = isset($value['spam_complaint_rate']) ? round((float) $value['spam_complaint_rate'] * 100, 3) : null;

        $body = 'Email deliverability issues detected.';
        if ($hardBounces > 0) {
            $body .= " Hard bounces: {$hardBounces}.";
        }
        if ($spamRate !== null) {
            $body .= " Spam complaint rate: {$spamRate}%.";
        }
        $body .= ' Review list hygiene before sending future campaigns.';

        return $this->supersedeKnowledge($learning, 'channel.email.health', $body, self::SAFETY_CONFIDENCE);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function emailListQualityKnowledge(Learning $learning): array
    {
        /** @var array<string, mixed> $value */
        $value = $learning->value ?? [];
        $rate = isset($value['unsubscribe_rate']) ? round((float) $value['unsubscribe_rate'] * 100, 2) : null;

        $body = 'High unsubscribe rate detected';
        if ($rate !== null) {
            $body .= " ({$rate}%)";
        }
        $body .= '. Review content relevance and sending frequency.';

        return $this->supersedeKnowledge($learning, 'channel.email.list_quality', $body, self::SAFETY_CONFIDENCE);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function campaignEffectivenessKnowledge(Learning $learning, string $effectiveness): array
    {
        /** @var array<string, mixed> $value */
        $value = $learning->value ?? [];
        $campaignType = (string) ($value['campaign_type'] ?? '');

        if ($campaignType === '') {
            return [];
        }

        $subject = "campaign.{$campaignType}.effectiveness";
        $body = match ($effectiveness) {
            'effective' => "'{$campaignType}' campaigns consistently exceed performance expectations for this company.",
            default => "'{$campaignType}' campaigns consistently underperform expectations. Consider reducing frequency or adjusting strategy.",
        };

        return $this->supersedeKnowledge($learning, $subject, $body, self::DEFAULT_CONFIDENCE);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function contentAngleKnowledge(Learning $learning): array
    {
        /** @var array<string, mixed> $value */
        $value = $learning->value ?? [];
        $campaignType = (string) ($value['campaign_type'] ?? '');

        /** @var list<string> $angles */
        $angles = (array) ($value['angles'] ?? []);

        if ($campaignType === '' || empty($angles)) {
            return [];
        }

        $angleList = implode(', ', $angles);
        $subject = "content.angle.{$campaignType}";
        $body = "Content angles '{$angleList}' drove strong engagement in '{$campaignType}' campaigns. Favour these angles in future content.";

        return $this->supersedeKnowledge($learning, $subject, $body, self::DEFAULT_CONFIDENCE);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function timingKnowledge(Learning $learning): array
    {
        /** @var array<string, mixed> $value */
        $value = $learning->value ?? [];
        $channelType = (string) ($value['channel_type'] ?? '');
        $hour = isset($value['published_hour']) ? (int) $value['published_hour'] : null;

        if ($channelType === '' || $hour === null) {
            return [];
        }

        $subject = "timing.{$channelType}.optimal_hour";
        $body = "Optimal send time for {$channelType} campaigns is {$hour}:00. Scheduling within this window yields above-average engagement.";

        return $this->supersedeKnowledge($learning, $subject, $body, 65);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function recommendationPreferenceKnowledge(Learning $learning, string $sentiment): array
    {
        /** @var array<string, mixed> $value */
        $value = $learning->value ?? [];
        $campaignType = (string) ($value['campaign_type'] ?? '');

        if ($campaignType === '') {
            return [];
        }

        $subject = "preference.campaign_type.{$campaignType}";
        $body = match ($sentiment) {
            'positive' => "'{$campaignType}' recommendations are consistently approved. This campaign type resonates well.",
            default => "'{$campaignType}' recommendations are frequently rejected. Reduce frequency or reconsider the approach.",
        };

        return $this->supersedeKnowledge($learning, $subject, $body, self::DEFAULT_CONFIDENCE);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function editedApprovalKnowledge(Learning $learning): array
    {
        /** @var array<string, mixed> $value */
        $value = $learning->value ?? [];
        $campaignType = (string) ($value['campaign_type'] ?? '');

        if ($campaignType === '') {
            return [];
        }

        $subject = "preference.campaign_type.{$campaignType}.edits";
        $body = "'{$campaignType}' recommendations are often approved with edits. Review content defaults and generation prompts for this campaign type.";

        return $this->supersedeKnowledge($learning, $subject, $body, 60);
    }

    /**
     * Deactivate any existing Knowledge entry with the same subject and create a new one.
     *
     * @return list<array<string, mixed>>
     */
    private function supersedeKnowledge(
        Learning $learning,
        string $subject,
        string $body,
        int $confidence,
    ): array {
        $existing = Knowledge::withoutGlobalScopes()
            ->where('company_id', $learning->company_id)
            ->where('subject', $subject)
            ->where('type', 'learning')
            ->where('is_active', true)
            ->first();

        $newEntry = Knowledge::create([
            'company_id' => $learning->company_id,
            'type' => 'learning',
            'subject' => $subject,
            'body' => $body,
            'structured' => null,
            'source_fact_ids' => null,
            'confidence' => $confidence,
            'is_active' => true,
            'generated_at' => now(),
            'expires_at' => now()->addDays(self::TTL_DAYS),
        ]);

        if ($existing !== null) {
            $existing->update(['is_active' => false]);
        }

        return [[
            'type' => 'knowledge_mutation',
            'knowledge_id' => $newEntry->id,
            'previous_knowledge_id' => $existing?->id,
            'subject' => $subject,
            'description' => "Knowledge '{$subject}' updated from signal '{$learning->signal}'",
        ]];
    }
}
