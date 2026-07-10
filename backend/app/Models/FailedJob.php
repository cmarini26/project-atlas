<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Maps to Laravel's own `failed_jobs` table (created by the framework's
 * `create_jobs_table` migration, not a domain migration). No company scope —
 * this is a system-wide operational table, not tenant data.
 */
class FailedJob extends Model
{
    protected $table = 'failed_jobs';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'failed_at' => 'datetime',
    ];

    /**
     * The job's display name (usually its class), read from the same
     * payload Laravel itself stores every queued job under.
     */
    public function jobClass(): string
    {
        /** @var array<string, mixed> $payload */
        $payload = json_decode((string) $this->payload, true) ?? [];

        return (string) ($payload['displayName'] ?? $payload['job'] ?? 'Unknown');
    }

    /**
     * The exception column holds a full stack trace; the first line is
     * exception class + message, which is what operators actually want to
     * scan in a list view.
     */
    public function exceptionSummary(): string
    {
        $firstLine = strtok((string) $this->exception, "\n");

        return $firstLine !== false ? $firstLine : (string) $this->exception;
    }
}
