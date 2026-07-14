<?php

namespace App\Models;

use App\Domain\Shared\Concerns\BelongsToCompany;
use App\Enums\MarketingChannelImportance;
use App\Enums\MarketingChannelObjective;
use App\Enums\MarketingChannelStatus;
use App\Enums\MarketingChannelType;
use App\Enums\PostingFrequency;
use Database\Factories\MarketingChannelFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\Rule;

/**
 * A single declared marketing channel for a Company — a fact about where and
 * how the business markets, independent of Atlas's technical ability to act
 * on it. See specs/core/marketing-presence.md §2.
 *
 * @property MarketingChannelType $type
 * @property MarketingChannelStatus $status
 * @property MarketingChannelImportance $importance
 * @property PostingFrequency|null $posting_frequency
 * @property list<string> $objective
 */
class MarketingChannel extends Model
{
    /** @use HasFactory<MarketingChannelFactory> */
    use BelongsToCompany, HasFactory, HasUlids;

    protected $fillable = [
        'company_id',
        'channel_id',
        'integration_id',
        'type',
        'display_name',
        'handle_or_url',
        'status',
        'importance',
        'objective',
        'audience',
        'posting_frequency',
        'notes',
        'is_connected',
        'supports_publishing',
        'supports_analytics',
        'metadata',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'type' => MarketingChannelType::class,
            'status' => MarketingChannelStatus::class,
            'importance' => MarketingChannelImportance::class,
            'posting_frequency' => PostingFrequency::class,
            'objective' => 'array',
            'metadata' => 'array',
            'is_connected' => 'boolean',
            'supports_publishing' => 'boolean',
            'supports_analytics' => 'boolean',
        ];
    }

    /** @return BelongsTo<Channel, $this> */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    /** @return BelongsTo<Integration, $this> */
    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }

    /** @param Builder<MarketingChannel> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', MarketingChannelStatus::Active);
    }

    /** @param Builder<MarketingChannel> $query */
    public function scopePrimary(Builder $query): void
    {
        $query->where('importance', MarketingChannelImportance::Primary);
    }

    /**
     * Connected via either linkage path: a real publishing Channel (link())
     * or an observation-source Integration (linkIntegration()) — see
     * docs/specs/Business-Discovery-Onboarding.md §3.1.
     *
     * @param  Builder<MarketingChannel>  $query
     */
    public function scopeConnected(Builder $query): void
    {
        $query->where('is_connected', true)
            ->where(fn (Builder $q) => $q->whereNotNull('channel_id')->orWhereNotNull('integration_id'));
    }

    /**
     * Structural validation rules — enum membership and shape only. Deliberately
     * does not include the soft duplicate-handle_or_url check described in
     * specs/core/marketing-presence.md §2, which requires querying existing
     * rows and belongs in MarketingPresenceService (Phase 2), not here.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(MarketingChannelType::class)],
            'display_name' => ['required', 'string', 'min:1'],
            'handle_or_url' => ['nullable', 'string'],
            'status' => ['required', Rule::enum(MarketingChannelStatus::class)],
            'importance' => ['required', Rule::enum(MarketingChannelImportance::class)],
            'objective' => ['required', 'array', 'min:1'],
            'objective.*' => [Rule::enum(MarketingChannelObjective::class)],
            'audience' => ['nullable', 'string'],
            'posting_frequency' => ['nullable', Rule::enum(PostingFrequency::class)],
            'notes' => ['nullable', 'string'],
        ];
    }
}
