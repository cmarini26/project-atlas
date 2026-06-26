<?php

namespace App\Services\Publishing\Exceptions;

class ContentPolicyViolationException extends PublishingException
{
    public function __construct(string $message = 'Content rejected for policy violation.')
    {
        parent::__construct($message, retryable: false);
    }

    public function userMessage(): string
    {
        return 'The platform rejected this post for policy reasons. Review the content and edit before retrying.';
    }
}
