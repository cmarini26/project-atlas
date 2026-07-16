<?php

namespace App\Models;

use App\Domain\Shared\Concerns\BelongsToCompany;
use App\Enums\EmailAudienceStatus;
use App\Enums\EmailContactStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** @property EmailAudienceStatus $status */
class EmailAudience extends Model
{
    use BelongsToCompany, HasUlids;

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'name',
        'status',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'status' => EmailAudienceStatus::class,
        ];
    }

    /** @return BelongsToMany<EmailContact, $this> */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(EmailContact::class, 'email_audience_members', 'email_audience_id', 'email_contact_id')
            ->withTimestamps();
    }

    /**
     * Active members only — the set a real send would actually reach.
     *
     * @return BelongsToMany<EmailContact, $this>
     */
    public function activeMembers(): BelongsToMany
    {
        return $this->members()->where('email_contacts.status', EmailContactStatus::Active->value);
    }

    /** @return HasMany<Campaign, $this> */
    public function campaigns(): HasMany
    {
        return $this->hasMany(Campaign::class);
    }
}
