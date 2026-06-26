<?php

namespace App\Services\Publishing;

use App\Models\Campaign;
use App\Models\ContentAsset;
use App\Models\Execution;
use App\Services\Publishing\Contracts\SupportsRollback;
use Illuminate\Support\Facades\Log;

class RollbackService
{
    public function __construct(
        private readonly ChannelPublisherRegistry $registry,
    ) {}

    /**
     * Attempt to roll back all completed Executions for a campaign.
     * Email and SMS channels cannot be rolled back — these are reported as unrollable.
     *
     * @return array{rolled_back: list<string>, unrollable: list<string>, failed: list<string>}
     */
    public function rollback(Campaign $campaign): array
    {
        $executions = Execution::withoutGlobalScopes()
            ->where('campaign_id', $campaign->id)
            ->where('status', 'completed')
            ->get();

        $rolledBack = [];
        $unrollable = [];
        $failed = [];

        foreach ($executions as $execution) {
            $channel = $execution->channel;

            if ($channel === null) {
                $failed[] = $execution->id;
                continue;
            }

            $publisher = $this->registry->for($channel->type);

            if (! ($publisher instanceof SupportsRollback)) {
                $unrollable[] = $execution->id;
                continue;
            }

            try {
                $success = $publisher->rollback($execution);

                if ($success) {
                    ContentAsset::withoutGlobalScopes()
                        ->where('id', $execution->content_asset_id)
                        ->update(['status' => 'archived']);

                    $rolledBack[] = $execution->id;
                } else {
                    $failed[] = $execution->id;
                }
            } catch (\Throwable $e) {
                Log::error('RollbackService: rollback failed.', [
                    'execution_id' => $execution->id,
                    'error' => $e->getMessage(),
                ]);

                $failed[] = $execution->id;
            }
        }

        return [
            'rolled_back' => $rolledBack,
            'unrollable' => $unrollable,
            'failed' => $failed,
        ];
    }
}
