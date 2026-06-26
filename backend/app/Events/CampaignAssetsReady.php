<?php

namespace App\Events;

use App\Models\Campaign;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CampaignAssetsReady
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly Campaign $campaign) {}
}
