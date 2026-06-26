<?php

namespace App\Services\Publishing;

use App\Services\Publishing\Contracts\ChannelRenderer;
use App\Services\Publishing\Exceptions\UnknownChannelException;

class ChannelRendererRegistry
{
    /** @var list<ChannelRenderer> */
    private array $renderers = [];

    public function register(ChannelRenderer $renderer): void
    {
        $this->renderers[] = $renderer;
    }

    public function for(string $channelType): ChannelRenderer
    {
        foreach ($this->renderers as $renderer) {
            if ($renderer->supports($channelType)) {
                return $renderer;
            }
        }

        throw new UnknownChannelException($channelType);
    }

    /** @return list<ChannelRenderer> */
    public function all(): array
    {
        return $this->renderers;
    }
}
