<?php

namespace App\Services\Publishing\Email;

use App\Domain\Publishing\ValueObjects\EmailPayload;
use App\Domain\Publishing\ValueObjects\PlatformPayload;
use App\Enums\EmailAudienceStatus;
use App\Enums\EmailConsentStatus;
use App\Enums\EmailContactSource;
use App\Enums\EmailContactStatus;
use App\Enums\EmailRecipientSnapshotStatus;
use App\Models\Campaign;
use App\Models\Company;
use App\Models\EmailAudience;
use App\Models\EmailContact;
use App\Models\EmailRecipientSnapshot;
use App\Models\Execution;
use App\Services\Publishing\Email\Exceptions\ContactBelongsToDifferentCompanyException;
use Illuminate\Support\Collection;

/**
 * Company-scoped email contacts, audiences, membership, and recipient
 * snapshotting — the minimal model that lets an Email campaign target more
 * than one recipient. See docs/architecture/EmailArchitecture.md for the
 * broader Phase 1A plan this is one slice of.
 *
 * Email identity normalization rule (applies everywhere in this class):
 * trim surrounding whitespace, then lowercase the whole address
 * (EmailContact::normalizeEmail()). Chosen because every mainstream mailbox
 * provider and Postmark itself already treat addresses case-insensitively
 * for delivery purposes, so treating two differently-cased submissions of
 * the same address as distinct contacts would only create silent
 * duplicates, never a real distinction. Internationalized (non-ASCII)
 * addresses are accepted as UTF-8 and normalized the same way (trim +
 * lowercase) — true IDN/punycode domain normalization is NOT implemented in
 * this slice; Postmark's own handling of non-ASCII domains is unverified,
 * so this deliberately does not claim support it hasn't confirmed. This is
 * a documented limitation, not an oversight.
 *
 * This slice stops at model + payload expansion — it deliberately does not
 * wire a real multi-recipient send yet. See
 * docs/architecture/EmailArchitecture.md §6 for the exact remaining
 * execution slice.
 */
class EmailAudienceService
{
    public function createAudience(Company $company, string $name): EmailAudience
    {
        return EmailAudience::create([
            'company_id' => $company->id,
            'name' => $name,
            'status' => EmailAudienceStatus::Active,
        ]);
    }

    public function renameAudience(EmailAudience $audience, string $name): EmailAudience
    {
        $audience->update(['name' => $name]);

        return $audience;
    }

    /**
     * A soft, reversible disable — mirrors
     * MarketingPresenceService::disable()'s status-flip convention. Members
     * remain in the pivot table; the audience simply stops being offered
     * for campaign targeting or resolving any recipients while archived.
     */
    public function archiveAudience(EmailAudience $audience): EmailAudience
    {
        $audience->update(['status' => EmailAudienceStatus::Archived]);

        return $audience;
    }

    /**
     * Creates a contact, or — if a contact with this company's normalized
     * email already exists (active or archived) — updates that same row in
     * place and reactivates it. This is the same updateOrCreate() upsert
     * idiom already used throughout the codebase (Channel/ChannelCredentials
     * connect flows), and it is specifically what makes "recreating an
     * archived contact" well-defined: it is never a second row racing the
     * unique (company_id, normalized_email) constraint, it is always this
     * one row coming back to 'active'.
     */
    public function addOrReactivateContact(
        Company $company,
        string $email,
        ?string $displayName = null,
        EmailContactSource $source = EmailContactSource::Manual,
        EmailConsentStatus $consentStatus = EmailConsentStatus::Unknown,
    ): EmailContact {
        $normalized = EmailContact::normalizeEmail($email);

        return EmailContact::withoutGlobalScopes()->updateOrCreate(
            ['company_id' => $company->id, 'normalized_email' => $normalized],
            [
                'email' => trim($email),
                'display_name' => $displayName,
                'source' => $source,
                'consent_status' => $consentStatus,
                'status' => EmailContactStatus::Active,
            ],
        );
    }

    public function archiveContact(EmailContact $contact): EmailContact
    {
        $contact->update(['status' => EmailContactStatus::Archived]);

        return $contact;
    }

    /**
     * @throws ContactBelongsToDifferentCompanyException
     */
    public function addMember(EmailAudience $audience, EmailContact $contact): void
    {
        if ($contact->company_id !== $audience->company_id) {
            throw new ContactBelongsToDifferentCompanyException(sprintf(
                'EmailContact %s belongs to company %s, but EmailAudience %s belongs to company %s.',
                $contact->id,
                $contact->company_id,
                $audience->id,
                $audience->company_id,
            ));
        }

        // syncWithoutDetaching is itself idempotent, and the pivot's own
        // unique(email_audience_id, email_contact_id) constraint is the
        // DB-level backstop against a duplicate membership row.
        $audience->members()->syncWithoutDetaching([$contact->id]);
    }

    public function removeMember(EmailAudience $audience, EmailContact $contact): void
    {
        $audience->members()->detach($contact->id);
    }

    /**
     * Persists an immutable snapshot of $audience's current active
     * membership as the intended recipients of $execution — the record
     * queued/later processing must read instead of re-querying live
     * audience membership, so a membership change made after this call can
     * never retroactively change who a specific Execution was intended to
     * reach. Safe to call at most meaningfully once per Execution (the
     * unique(execution_id, email) constraint prevents a second call from
     * creating duplicate rows for addresses already snapshotted; calling it
     * again is a no-op for those addresses since updateOrCreate matches on
     * that same pair).
     *
     * Defensive per-batch deduplication by normalized email guards against
     * a future multi-audience-per-campaign feature (not built in this
     * slice) producing the same address twice in one resolution — today,
     * with exactly one audience per campaign and unique contacts per
     * company, this path cannot actually be exercised, but the guard costs
     * nothing to keep and removes a sharp edge for that later feature.
     *
     * @return Collection<int, EmailRecipientSnapshot>
     */
    public function snapshotRecipientsForExecution(Execution $execution, Campaign $campaign, EmailAudience $audience): Collection
    {
        /** @var Collection<int, EmailContact> $members */
        $members = $audience->activeMembers()->get();

        /** @var array<string, true> $seen */
        $seen = [];
        /** @var Collection<int, EmailRecipientSnapshot> $snapshots */
        $snapshots = collect();

        foreach ($members as $contact) {
            $normalized = $contact->normalized_email;

            $skipped = isset($seen[$normalized]);
            $seen[$normalized] = true;

            if ($skipped) {
                continue;
            }

            $snapshots->push(EmailRecipientSnapshot::withoutGlobalScopes()->updateOrCreate(
                ['execution_id' => $execution->id, 'email' => $contact->email],
                [
                    'company_id' => $campaign->company_id,
                    'campaign_id' => $campaign->id,
                    'email_contact_id' => $contact->id,
                    'display_name' => $contact->display_name,
                    'status' => EmailRecipientSnapshotStatus::Pending,
                    'skipped_reason' => null,
                ],
            ));
        }

        return $snapshots;
    }

    /**
     * Expands one rendered campaign payload into one EmailPayload per
     * pending snapshot recipient. Deliberately does NOT change EmailPayload
     * or EmailProvider's shape — each recipient still gets its own
     * single-recipient EmailPayload, exactly the shape PostmarkEmailProvider
     * already accepts, so per-recipient isolation (failures, provider
     * message IDs, retries, future unsubscribe links, future analytics
     * correlation) falls out of the existing one-call-per-send contract for
     * free.
     *
     * This method only prepares payloads — it does not send anything.
     * Wiring a real send loop that calls EmailProvider::send() once per
     * payload and records the per-recipient outcome back onto its snapshot
     * is the next slice (see docs/architecture/EmailArchitecture.md §6).
     *
     * @param  Collection<int, EmailRecipientSnapshot>  $snapshots
     * @return Collection<int, EmailPayload>
     */
    public function buildPayloadsForSnapshots(PlatformPayload $platformPayload, Collection $snapshots): Collection
    {
        return $snapshots
            ->filter(fn (EmailRecipientSnapshot $s) => $s->status === EmailRecipientSnapshotStatus::Pending)
            ->map(fn (EmailRecipientSnapshot $s) => new EmailPayload(
                subject: (string) ($platformPayload->data['subject'] ?? ''),
                fromName: (string) ($platformPayload->data['from_name'] ?? ''),
                fromEmail: (string) ($platformPayload->data['from_email'] ?? ''),
                body: (string) ($platformPayload->data['body'] ?? ''),
                previewText: (string) ($platformPayload->data['preview_text'] ?? ''),
                toEmail: $s->email,
                toName: $s->display_name,
            ))
            ->values();
    }
}
