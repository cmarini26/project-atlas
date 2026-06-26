<?php

namespace App\Services\Publishing\Exceptions;

class MalformedPayloadException extends PublishingException
{
    public function __construct(string $message = 'Publisher produced a malformed payload.')
    {
        parent::__construct($message, retryable: false);
    }

    public function userMessage(): string
    {
        return 'Content could not be formatted for publishing. Review the content and try again.';
    }
}
