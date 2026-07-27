<?php

namespace App\Services\Observatory\Connectors\Mailchimp;

use App\Enums\EmailConsentStatus;
use App\Enums\EmailContactSource;
use App\Models\EmailAudience;
use App\Models\EmailContact;
use App\Models\Integration;
use App\Services\Observatory\Connectors\ConnectorResult;
use App\Services\Observatory\Connectors\Contracts\Connector;
use App\Services\Publishing\Email\EmailAudienceService;
use DateTimeImmutable;
use Illuminate\Support\Collection;

class MailchimpConnector implements Connector
{
    public function __construct(
        private readonly MailchimpApiClient $client,
        private readonly EmailAudienceService $audiences,
    ) {}

    public function supports(Integration $integration): bool
    {
        return $integration->type === 'mailchimp';
    }

    /** @return Collection<int, ConnectorResult> */
    public function sync(Integration $integration): Collection
    {
        $serverPrefix = (string) ($integration->config['server_prefix'] ?? '');
        $apiKey = (string) ($integration->config['api_key'] ?? '');
        $audienceId = (string) ($integration->config['audience_id'] ?? '');

        $audiencePayload = $this->client->fetchAudience($serverPrefix, $apiKey, $audienceId);
        $members = $this->client->fetchMembers($serverPrefix, $apiKey, $audienceId);
        $company = $integration->company;
        $atlasAudience = $this->resolveAtlasAudience($integration, (string) $audiencePayload['name']);
        $syncedContactIds = [];
        $imported = 0;
        $nonSendable = 0;

        foreach ($members as $member) {
            $email = (string) ($member['email_address'] ?? '');
            if ($email === '') {
                continue;
            }

            $status = (string) ($member['status'] ?? '');
            $displayName = $this->displayNameFor($member);

            if ($status === 'subscribed') {
                $contact = $this->audiences->addOrReactivateContact(
                    $company,
                    $email,
                    $displayName,
                    EmailContactSource::Import,
                    EmailConsentStatus::Confirmed,
                );
                $this->audiences->addMember($atlasAudience, $contact);
                $syncedContactIds[] = $contact->id;
                $imported++;

                continue;
            }

            $contact = $this->audiences->addOrReactivateContact(
                $company,
                $email,
                $displayName,
                EmailContactSource::Import,
                $status === 'unsubscribed' || $status === 'cleaned' ? EmailConsentStatus::Declined : EmailConsentStatus::Unknown,
            );

            $this->audiences->removeMember($atlasAudience, $contact);
            $nonSendable++;
        }

        $atlasAudience->members()
            ->whereNotIn('email_contacts.id', $syncedContactIds === [] ? ['__none__'] : $syncedContactIds)
            ->get()
            ->each(fn (EmailContact $contact) => $this->audiences->removeMember($atlasAudience, $contact));

        $observedAt = new DateTimeImmutable();

        return collect([
            new ConnectorResult(
                sourceType: 'api',
                sourceIdentifier: sprintf('mailchimp:%s', $audienceId),
                payload: json_encode([
                    'mailchimp_audience' => [
                        'id' => $audiencePayload['id'],
                        'name' => $audiencePayload['name'],
                        'member_count' => $audiencePayload['stats']['member_count'] ?? null,
                    ],
                    'atlas_audience_id' => $atlasAudience->id,
                    'imported_contacts' => $imported,
                    'non_sendable_contacts' => $nonSendable,
                ], JSON_THROW_ON_ERROR),
                observedAt: $observedAt,
            ),
        ]);
    }

    private function resolveAtlasAudience(Integration $integration, string $mailchimpAudienceName): EmailAudience
    {
        $existingId = $integration->config['atlas_audience_id'] ?? null;
        $company = $integration->company;

        if (is_string($existingId) && $existingId !== '') {
            $existing = EmailAudience::withoutGlobalScopes()
                ->where('company_id', $company->id)
                ->find($existingId);

            if ($existing !== null) {
                if ($existing->name !== $mailchimpAudienceName) {
                    $this->audiences->renameAudience($existing, $mailchimpAudienceName);
                }

                return $existing;
            }
        }

        $byName = EmailAudience::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('name', $mailchimpAudienceName)
            ->first();

        $audience = $byName ?? $this->audiences->createAudience($company, $mailchimpAudienceName);

        $config = $integration->config;
        $config['atlas_audience_id'] = $audience->id;
        $integration->update(['config' => $config]);

        return $audience;
    }

    /** @param array<string, mixed> $member */
    private function displayNameFor(array $member): ?string
    {
        $mergeFields = $member['merge_fields'] ?? [];

        if (! is_array($mergeFields)) {
            return null;
        }

        $first = trim((string) ($mergeFields['FNAME'] ?? ''));
        $last = trim((string) ($mergeFields['LNAME'] ?? ''));
        $name = trim("{$first} {$last}");

        return $name !== '' ? $name : null;
    }
}
