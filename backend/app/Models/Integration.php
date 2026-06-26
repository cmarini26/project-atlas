<?php

namespace App\Models;

use App\Domain\Shared\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property array<string, mixed> $config
 */
class Integration extends Model
{
    use BelongsToCompany, HasUlids;

    protected $fillable = [
        'company_id',
        'type',
        'name',
        'config',
        'status',
        'last_run_at',
        'last_successful_run_at',
        'next_run_at',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'config' => 'encrypted:array',
            'last_run_at' => 'datetime',
            'last_successful_run_at' => 'datetime',
            'next_run_at' => 'datetime',
        ];
    }

    /** @return HasMany<Observation, $this> */
    public function observations(): HasMany
    {
        return $this->hasMany(Observation::class);
    }

    public function markAsError(string $message): void
    {
        $this->update([
            'status' => 'error',
            'last_error' => $message,
        ]);
    }
}
