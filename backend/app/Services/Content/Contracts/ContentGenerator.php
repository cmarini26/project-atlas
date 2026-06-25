<?php

namespace App\Services\Content\Contracts;

use App\Models\Campaign;
use App\Models\ContentAsset;

interface ContentGenerator
{
    /**
     * Returns the channel slug this generator handles (e.g. 'email', 'instagram').
     */
    public function channel(): string;

    /**
     * Generate a ContentAsset for the given campaign and channel.
     */
    public function generate(Campaign $campaign): ContentAsset;
}
