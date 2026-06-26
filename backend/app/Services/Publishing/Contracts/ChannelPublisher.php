<?php

namespace App\Services\Publishing\Contracts;

use App\Domain\Publishing\ValueObjects\ExecutionResult;
use App\Domain\Publishing\ValueObjects\PingResult;
use App\Models\ChannelCredentials;
use App\Models\Execution;
use App\Services\Publishing\Exceptions\PublishingException;

interface ChannelPublisher
{
    /**
     * Publish the content asset described by the given Execution.
     *
     * @throws PublishingException
     */
    public function publish(Execution $execution): ExecutionResult;

    /**
     * Returns true if this publisher handles the given channel type.
     */
    public function supports(string $channelType): bool;

    /**
     * Verify that the publisher can reach the platform with the given credentials.
     * Does not publish anything.
     */
    public function ping(ChannelCredentials $credentials): PingResult;
}
