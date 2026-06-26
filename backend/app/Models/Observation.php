<?php

namespace App\Models;

use App\Domain\Shared\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Observation extends Model
{
    use BelongsToCompany, HasUlids, Prunable;

    protected $fillable = [
        'company_id',
        'integration_id',
        'source_type',
        'source_identifier',
        'raw_payload',
        'raw_payload_ref',
        'status',
        'observed_at',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'observed_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Integration, $this> */
    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }

    /** @return HasMany<Fact, $this> */
    public function facts(): HasMany
    {
        return $this->hasMany(Fact::class);
    }

    /** @return Builder<Observation> */
    public function prunable(): Builder
    {
        return static::where('status', 'processed')
            ->where('processed_at', '<=', now()->subDays(180));
    }

    /** Null out the raw payload before the row is pruned. */
    protected function pruning(): void
    {
        $this->raw_payload = null;
        $this->save();
    }

    public function markProcessing(): void
    {
        $this->update(['status' => 'processing']);
    }

    public function markProcessed(): void
    {
        $this->update([
            'status' => 'processed',
            'processed_at' => now(),
        ]);
    }

    public function markFailed(): void
    {
        $this->update(['status' => 'failed']);
    }
}
