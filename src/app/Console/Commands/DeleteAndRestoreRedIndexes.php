<?php

namespace App\Console\Commands;

use App\Contracts\SearchEngineInterface;
use Exception;
use Illuminate\Console\Command;

class DeleteAndRestoreRedIndexes extends Command
{
    const RED_INDICES = [
        "requests-2022.05.17",
        "requests-2021.10.18",
        "requests-2021.10.23",
        "requests-2021.10.26",
        "requests-2021.10.28",
        "requests-2021.10.20",
        "requests-2021.10.05",
    ];

    /**
     * @var string
     */
    protected $signature   = 'elastic:restore-red-indexes';

    /**
     * @var string
     */
    protected $description = 'Deletes and restores the red indices';

    public function handle(SearchEngineInterface $elastic): void
    {
        foreach (self::RED_INDICES as $indexName) {
            $this->output->info('Starting process for index: ' . $indexName);
            $forceTry = false;

            try {
                $index = $elastic->getIndex($indexName);
            } catch (Exception $e) {
                $forceTry = true;
            }
            if ($index[0]['health'] === 'red' || $forceTry) {
                $snapshot = $elastic->getLatestSnapshotForAlias('-' . $indexName);

                if ($snapshot) {
                    if (!$forceTry) {
                        $elastic->deleteIndex(
                            '*',
                            $indexName,
                        );
                    }

                    try {
                        $restored = $elastic->restoreIndexFromSnapshot(
                            $snapshot['snapshot'],
                            $snapshot['repository'],
                            $indexName,
                        );

                        if (!$restored) {
                            $this->output->error($indexName . ' could not be restored, please retry.');
                            continue;
                        }
                    } catch (Exception $e) {
                        $this->output->error('Index  "' . $indexName . '" could not be restored from Snapshot, skipping');
                        $this->output->error($e->getMessage());
                        continue;
                    }
                    $this->output->success($indexName . ' index restored successfully.');
                } else {
                    $this->output->error('No snapshots for: ' . $indexName);
                }
            } else {
                $this->output->info('Index health is: ' . $index[0]['health']);
            }
        }
    }

}

