<?php

namespace App\Services\Publishing;

use App\Services\Publishing\Contracts\ChannelPublisher;
use App\Services\Publishing\Exceptions\UnknownChannelException;

class ChannelPublisherRegistry
{
    /** @var list<ChannelPublisher> */
    private array $publishers = [];

    public function register(ChannelPublisher $publisher): void
    {
        $this->publishers[] = $publisher;
    }

    public function for(string $channelType): ChannelPublisher
    {
        foreach ($this->publishers as $publisher) {
            if ($publisher->supports($channelType)) {
                return $publisher;
            }
        }

        throw new UnknownChannelException($channelType);
    }

    /** @return list<ChannelPublisher> */
    public function all(): array
    {
        return $this->publishers;
    }
}
