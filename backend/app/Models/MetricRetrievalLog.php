<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MetricRetrievalLog extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'execution_id',
        'provider_type',
        'attempted_at',
        'status',
        'error',
        'response_code',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'attempted_at' => 'datetime',
        'response_code' => 'integer',
    ];

    /** @return BelongsTo<Execution, $this> */
    public function execution(): BelongsTo
    {
        return $this->belongsTo(Execution::class);
    }
}
