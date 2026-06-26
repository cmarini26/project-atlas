<?php

namespace App\Services\Publishing\Email;

use App\Domain\Publishing\ValueObjects\EmailPayload;
use App\Domain\Publishing\ValueObjects\PingResult;
use App\Models\ChannelCredentials;
use App\Services\Publishing\Email\Contracts\EmailProvider;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class LogEmailProvider implements EmailProvider
{
    public function send(EmailPayload $payload, ChannelCredentials $credentials): string
    {
        $messageId = 'log-email-'.Str::ulid()->toString();

        Log::channel('publishing')->info('LogEmailProvider: simulating email send', [
            'message_id' => $messageId,
            'subject' => $payload->subject,
            'from' => "{$payload->fromName} <{$payload->fromEmail}>",
            'body_preview' => mb_substr($payload->body, 0, 120),
        ]);

        return $messageId;
    }

    public function ping(ChannelCredentials $credentials): PingResult
    {
        return new PingResult(reachable: true, error: null);
    }

    public function supports(string $providerType): bool
    {
        return $providerType === 'log';
    }
}
