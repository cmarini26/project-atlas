<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExecutionAttempt extends Model
{
    use HasUlids;

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'execution_id',
        'attempt_number',
        'attempted_at',
        'status',
        'error',
        'response',
        'created_at',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'attempted_at' => 'datetime',
        'created_at' => 'datetime',
        'response' => 'array',
    ];

    /** @return BelongsTo<Execution, $this> */
    public function execution(): BelongsTo
    {
        return $this->belongsTo(Execution::class);
    }
}
