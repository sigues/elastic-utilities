<?php

namespace App\Console\Commands;

use App\Contracts\SearchEngineInterface;
use Exception;
use Illuminate\Console\Command;

class ValidateElasticIndexes extends Command
{

    /**
     * @var string
     */
    protected $signature   = 'elastic:validate-partial-indexes';

    /**
     * @var string
     */
    protected $description = 'Valiate that all partial indexes have a version with no alias';

    public function handle(SearchEngineInterface $elastic): void
    {
        $indicesAliasResponse = $elastic->getAliases('partial-*');

        $this->output->info('Partial Indices found: ' . count($indicesAliasResponse));
        $i = 0;
        $indexesMigratedCorrectly = 0;
        $indexesMissingTheNewVersion = 0;

        foreach ($indicesAliasResponse as $index => $indicesAlias) {
            try {
                dump($indicesAlias);
                dump($index);
                $newIndexKey = str_replace('partial-', '', $index);
                $newIndex = $elastic->getIndex($newIndexKey);
                dump($newIndexKey);
                $indexesMigratedCorrectly++;
            } catch (Exception $e) {
                dump('new index does not exist: ' . $newIndexKey);
                $indexesMissingTheNewVersion++;
            }
            $i++;
        }

        $this->output->writeln('finished processing');
        $this->output->success('Indexes migrated correctly: ' . $indexesMigratedCorrectly);
        $this->output->error('Indexes without the new version: ' . $indexesMissingTheNewVersion);
    }
}

