<?php

namespace App\Services\Publishing;

use App\Domain\Publishing\ValueObjects\EmailPayload;
use App\Domain\Publishing\ValueObjects\ExecutionResult;
use App\Domain\Publishing\ValueObjects\PingResult;
use App\Domain\Publishing\ValueObjects\PlatformPayload;
use App\Models\Channel;
use App\Models\ChannelCredentials;
use App\Models\ContentAsset;
use App\Models\EmailRecipientSnapshot;
use App\Models\Execution;
use App\Services\Publishing\Contracts\ChannelPublisher;
use App\Services\Publishing\Email\EmailAudienceService;
use App\Services\Publishing\Email\EmailProviderRegistry;
use App\Services\Publishing\Exceptions\PublishingException;
use DateTimeImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class EmailPublisher implements ChannelPublisher
{
    public function __construct(
        private readonly ChannelRendererRegistry $renderers,
        private readonly ChannelCredentialsRepository $credentialsRepository,
        private readonly EmailProviderRegistry $emailProviders,
        private readonly EmailAudienceService $emailAudiences,
    ) {}

    public function publish(Execution $execution): ExecutionResult
    {
        $asset = ContentAsset::withoutGlobalScopes()->findOrFail($execution->content_asset_id);
        $channel = Channel::withoutGlobalScopes()->findOrFail($execution->channel_id);

        $credentials = $this->credentialsRepository->for($execution->company_id, 'email');

        $payload = $this->renderers->for($channel->type)->render($asset, $channel);

        // A recipient snapshot only exists when ExecutionService::
        // queueForCampaign() found a real audience selected on this
        // Execution's campaign (EmailAudienceService::snapshotIfApplicable()
        // — see its docblock). No snapshot at all means no audience was
        // ever selected for this campaign; fall back to the original
        // single-recipient path (Channel.config.to_email) unchanged, so
        // every existing email campaign/test keeps working exactly as
        // before this feature existed.
        $snapshots = EmailRecipientSnapshot::withoutGlobalScopes()
            ->where('execution_id', $execution->id)
            ->get();

        if ($snapshots->isNotEmpty()) {
            return $this->publishToAudience($execution, $credentials, $payload, $snapshots);
        }

        $emailPayload = EmailPayload::fromPlatformPayload($payload);

        $providerType = $credentials->provider_type ?? 'log';
        $provider = $this->emailProviders->for($providerType);

        $messageId = $provider->send($emailPayload, $credentials);

        return new ExecutionResult(
            platformId: $messageId,
            url: null,
            publishedAt: new DateTimeImmutable(),
            metadata: [
                'publisher' => 'email',
                'provider' => $providerType,
                'subject' => $emailPayload->subject,
            ],
        );
    }

    public function supports(string $channelType): bool
    {
        return $channelType === 'email';
    }

    public function ping(ChannelCredentials $credentials): PingResult
    {
        $providerType = $credentials->provider_type ?? 'log';

        return $this->emailProviders->for($providerType)->ping($credentials);
    }

    /**
     * Sends one EmailPayload per pending snapshot recipient — never a
     * shared To/CC/BCC header, one real EmailProvider::send() call per
     * address, exactly the existing single-recipient contract, called N
     * times. A bad address or a provider rejection for one recipient is
     * caught and recorded on that recipient's own snapshot row; it never
     * aborts the loop, so one failure can never silently block every other
     * recipient.
     *
     * Honesty about partial failure: this Execution's own ExecutionResult
     * represents "did the send attempt make real progress," not "did every
     * recipient succeed" — that per-recipient truth lives only in
     * email_recipient_snapshots, which a caller must read to know who
     * actually received it. If literally nothing sent (every recipient
     * failed, e.g. a full Postmark outage), this throws instead of
     * returning a false success — PublishContent's existing retry
     * machinery then retries the whole Execution exactly as it already
     * does for a single-recipient failure. If at least one recipient
     * succeeded, this Execution is reported completed and is NOT retried
     * even if others failed — retrying would re-send duplicates to the
     * recipients that already succeeded, which is worse than an honestly
     * partial result recorded per-recipient.
     *
     * @param  Collection<int, EmailRecipientSnapshot>  $snapshots
     *
     * @throws PublishingException when zero recipients were pending, or
     *                             every send attempt failed
     */
    private function publishToAudience(
        Execution $execution,
        ChannelCredentials $credentials,
        PlatformPayload $payload,
        Collection $snapshots,
    ): ExecutionResult {
        $providerType = $credentials->provider_type ?? 'log';
        $provider = $this->emailProviders->for($providerType);

        $payloadsBySnapshotId = $this->emailAudiences->buildPayloadsForSnapshots($payload, $snapshots);

        if ($payloadsBySnapshotId->isEmpty()) {
            // Either the audience genuinely has zero members, or every
            // member was already processed by a prior attempt on this same
            // Execution (all rows are already Sent/Failed, none Pending) —
            // either way there is nothing left to do, and reporting success
            // here would be exactly the "fake successful bulk sending" this
            // feature must not do.
            throw new PublishingException(
                'Email campaign has no pending recipients to send to — the selected audience may be empty.',
                retryable: false,
            );
        }

        $snapshotsById = $snapshots->keyBy('id');
        $sent = 0;
        $failed = 0;

        foreach ($payloadsBySnapshotId as $snapshotId => $emailPayload) {
            /** @var EmailRecipientSnapshot $snapshot */
            $snapshot = $snapshotsById->get($snapshotId);

            try {
                $messageId = $provider->send($emailPayload, $credentials);
                $this->emailAudiences->markSnapshotSent($snapshot, $messageId);
                $sent++;
            } catch (PublishingException $e) {
                $this->emailAudiences->markSnapshotFailed($snapshot, $e->getMessage());
                $failed++;
            }
        }

        Log::info('EmailPublisher: audience send attempt finished.', [
            'execution_id' => $execution->id,
            'campaign_id' => $execution->campaign_id,
            'sent' => $sent,
            'failed' => $failed,
        ]);

        if ($sent === 0) {
            throw new PublishingException(
                "Email campaign failed to send to any of {$failed} recipient(s) — the provider rejected every attempt.",
                retryable: true,
            );
        }

        return new ExecutionResult(
            platformId: "audience:{$execution->id}",
            url: null,
            publishedAt: new DateTimeImmutable(),
            metadata: [
                'publisher' => 'email',
                'provider' => $providerType,
                'subject' => $payload->data['subject'] ?? '',
                'recipients_total' => $sent + $failed,
                'recipients_sent' => $sent,
                'recipients_failed' => $failed,
            ],
        );
    }
}
