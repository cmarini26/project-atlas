<?php

namespace App\Models;

use App\Domain\Shared\Concerns\BelongsToCompany;
use App\Enums\EmailConsentStatus;
use App\Enums\EmailContactSource;
use App\Enums\EmailContactStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property EmailContactSource $source
 * @property EmailConsentStatus $consent_status
 * @property EmailContactStatus $status
 */
class EmailContact extends Model
{
    use BelongsToCompany, HasUlids;

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'email',
        'normalized_email',
        'display_name',
        'source',
        'consent_status',
        'status',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'source' => EmailContactSource::class,
            'consent_status' => EmailConsentStatus::class,
            'status' => EmailContactStatus::class,
        ];
    }

    /** @return BelongsToMany<EmailAudience, $this> */
    public function audiences(): BelongsToMany
    {
        return $this->belongsToMany(EmailAudience::class, 'email_audience_members', 'email_contact_id', 'email_audience_id')
            ->withTimestamps();
    }

    /**
     * The single normalization rule for email identity in Atlas: trim
     * surrounding whitespace, lowercase the whole address. This is a
     * deliberate simplification, not an oversight — see
     * EmailAudienceService's class docblock for the full reasoning
     * (case/whitespace/internationalized-address decisions in one place).
     */
    public static function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }
}
