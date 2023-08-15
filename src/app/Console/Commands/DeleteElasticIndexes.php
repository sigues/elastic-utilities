<?php

namespace App\Console\Commands;

use App\Contracts\SearchEngineInterface;
use Exception;
use Illuminate\Console\Command;

class DeleteElasticIndexes extends Command
{

    /**
     * @var string
     */
    protected $signature   = 'elastic:delete-partial-indexes';

    /**
     * @var string
     */
    protected $description = 'Fetch all alias with indexes and delete the indexes, then recreate them';

    /**
     * @var string
     * Wildcard to search indices
     */
    protected $wildcard = 'partial-*';

    const ALIAS_TO_REMOVE = 100;

    public function handle(SearchEngineInterface $elastic): void
    {
        $ILMStopped = $elastic->isILMStopped();

        if (!$ILMStopped) {
            $elastic->disableILM();
        }

        $indicesAliasResponse = $elastic->getAliases($this->wildcard);

        $this->output->info('Partial Indices found: ' . count($indicesAliasResponse));
        $aliasRemoved = 0;

        $this->output->writeln('<info>' . count($indicesAliasResponse) . '</info>');

        $i = 0;
        foreach ($indicesAliasResponse as $index => $indicesAlias) {
            if ($aliasRemoved == self::ALIAS_TO_REMOVE) {
                continue;
            }

            if (count($indicesAlias['aliases'])) {
                foreach ($indicesAlias['aliases'] as $alias => $value) {
                    try {
                        $aliasExist = $elastic->getIndex($alias);
                        foreach ($aliasExist as $existingAlias) {
                            if ($existingAlias['index'] == $alias) {
                                $this->output->success('Index was already migrated. ' . $alias);

                                continue 2;
                            }
                        }

                    } catch (Exception $e) {
                        dump($e->getMessage());
                    }

                    $this->output->comment($aliasRemoved . ' - Processing alias: "' . $alias . '"');
                    $snapshot = $elastic->getLatestSnapshotForAlias('-' . $alias);

                    if ($snapshot) {
                        if (!$elastic->removeAlias($index, $alias)) {
                            $this->output->writeln('<error>Could not remove alias "' . $alias . '", skipping</error>');
                            continue;
                        }

                        try {
                            $restored = $elastic->restoreIndexFromSnapshot(
                                $snapshot['snapshot'],
                                $snapshot['repository'],
                                $newIndex = $alias,
                            );
                            if (!$restored) {
                                $this->output->error($alias . ' could not be restored, please retry.');
                                continue;
                            }
                        } catch (Exception $e) {
                            $this->output->writeln('<error>Index  "' . $newIndex . '" could not be restored from Snapshot, skipping</error>');
                            $this->output->writeln('<error>' . $e->getMessage() . '</error>');
                            continue;
                        }

                        $tries = 0;
                        $retry = true;
                        while($retry) {
                            /** we need this sleep otherwise the count fails */
                            sleep(3);

                            try {
                                $oldIndexCount = $elastic->count($index);
                                $newIndexCount = $elastic->count($newIndex);

                                $this->output->info('got old and new index counts. ' . $oldIndexCount . '-' . $newIndexCount);
                                $retry = false;
                            } catch (Exception $e) {
                                /** if it has failed 5 times, just skip it */
                                if ($tries == 20) {
                                    $this->output->error('20 retries hit, exiting');
                                    $retry = false;
                                } else {
                                    $this->output->warning($tries . ' retry to count documents');
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

                    } else {
                        dd('snapshot not found');
                    }


                }

                $aliasRemoved++;
            } else {
                $this->output->info($i . ' - No aliases for: ' . $index);
            }
            $i++;
        }

        $this->output->writeln('finished processing');
    }
}

