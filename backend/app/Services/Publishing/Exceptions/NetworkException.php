<?php

namespace App\Services\Publishing\Exceptions;

class NetworkException extends PublishingException
{
    public function __construct(string $message = 'Network error contacting platform.')
    {
        parent::__construct($message, retryable: true);
    }

    public function userMessage(): string
    {
        return 'A network error occurred while publishing. Retrying automatically.';
    }
}
