<?php

namespace App\Console\Commands;

use App\Contracts\SearchEngineInterface;
use Exception;
use Illuminate\Console\Command;

class FixingSkippedAliases extends Command
{

    /**
     * @var string
     */
    protected $signature   = 'elastic:fixed-skipped-aliases';

    /**
     * @var string
     */
    protected $description = 'Fetch all alias with indexes and delete the indexes, then recreate them';

    public function handle(SearchEngineInterface $elastic): void
    {
        $indicesAliasResponse = $elastic->getAliases('partial-*');

        foreach ($indicesAliasResponse as $index => $indicesAlias) {
            // if the index doesn't have an alias anymore:
            // check if the new index exists

            $newIndex = str_replace('partial-', '', $index);
            $this->output->info($newIndex);

            try {
                $indexFromElastic = $elastic->getIndex($newIndex);
            } catch (Exception $e) {
                $this->output->info('new index doesnt exist yet');
                $indexFromElastic = false;
            }

            if (!$indexFromElastic) {
                $snapshot = $elastic->getLatestSnapshotForAlias('-' . $newIndex);

                try {
                    $elastic->restoreIndexFromSnapshot(
                        $snapshot['snapshot'],
                        $snapshot['repository'],
                        $newIndex,
                    );
                } catch (Exception $e) {
                    $this->output->writeln('<error>Index  "' . $newIndex . '" could not be restored from Snapshot, skipping</error>');
                    $this->output->writeln('<error>' . $e->getMessage() . '</error>');
                    continue;
                }
            }

            $tries = 0;
            $retry = true;
            while($retry) {
                /** we need this sleep otherwise the count fails */
                sleep(5);

                try {
                    $oldIndexCount = $elastic->count($index);
                    $newIndexCount = $elastic->count($newIndex);

                    $this->output->info('got old and new index counts. ' . $oldIndexCount . '-' . $newIndexCount);
                    $retry = false;
                } catch (Exception $e) {
                    /** if it has failed 5 times, just skip it */
                    if ($tries == 30) {
                        $this->output->error('30 retries hit, exiting');
                        $retry = false;
                    } else {
                        $this->output->info('trying to get the count for the new and old index, retry: ' . $tries);

                    }

                    $tries++;
                }

            }

            if ($oldIndexCount === $newIndexCount) {
                $elastic->deleteIndex(
                    '*',
                    $index,
                );

                $this->output->writeln('<fg=green>Count matches, deleted index: ' . $index . '</>');
            } else {
                throw new Exception('count doesn\t match for index: ' . $newIndex);
            }

            $this->output->success('finished ' . $newIndex);
        }
        dd('finished successfully');

    }

}
