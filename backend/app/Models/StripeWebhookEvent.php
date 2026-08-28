<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A record that Atlas received a given Stripe webhook event, keyed by Stripe's
 * event id for idempotency. Also the support-visibility log for "did the
 * webhook arrive, did we process it".
 */
class StripeWebhookEvent extends Model
{
    use HasUlids;

    /** @var list<string> */
    protected $fillable = [
        'stripe_event_id',
        'type',
        'received_at',
        'processed_at',
        'error',
    ];

    protected function casts(): array
    {
        return [
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    public function isProcessed(): bool
    {
        return $this->processed_at !== null;
    }
}
