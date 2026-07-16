<?php

namespace App\Models;

use App\Domain\Shared\Concerns\BelongsToCompany;
use App\Enums\EmailRecipientSnapshotStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** @property EmailRecipientSnapshotStatus $status */
class EmailRecipientSnapshot extends Model
{
    use BelongsToCompany, HasUlids;

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'campaign_id',
        'execution_id',
        'email_contact_id',
        'email',
        'display_name',
        'status',
        'skipped_reason',
        'provider_message_id',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'status' => EmailRecipientSnapshotStatus::class,
        ];
    }

    /** @return BelongsTo<Campaign, $this> */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /** @return BelongsTo<Execution, $this> */
    public function execution(): BelongsTo
    {
        return $this->belongsTo(Execution::class);
    }

    /** @return BelongsTo<EmailContact, $this> */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(EmailContact::class, 'email_contact_id');
    }
}
