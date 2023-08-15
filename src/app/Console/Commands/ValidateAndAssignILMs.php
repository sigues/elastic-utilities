<?php

namespace App\Console\Commands;

use App\Contracts\SearchEngineInterface;
use Illuminate\Console\Command;

class ValidateAndAssignILMs extends Command
{

    /**
     * @var string
     */
    protected $signature   = 'elastic:validate-ilms';

    /**
     * @var string
     */
    protected $description = 'Fetch all alias with indexes and delete the indexes, then recreate them';

    public function handle(SearchEngineInterface $elastic): void
    {
        $elastic->enableILM();
//        $indices = $elastic->getIndex('*-merged-*');
//        $indices = $elastic->getIndex('*');
        $indices = $elastic->getIndex('requests-merged-*');

        dump(count($indices));
        $indicesCount = [];
        foreach ($indices as $index) {
            $indexName = $index['index'];

            $this->output->info('processing ' . $indexName);

            $status = $elastic->getIndexILMStatus($indexName);

            if ($status['indices'][$indexName]['managed'] === false) {
                $response = $elastic->assignILMPolicyToIndex($indexName, 'hot-warm');
                if (!$response) {
                    dd($indexName . ' failed');
                } else {
                    $this->output->success($indexName . ' ILM policy assigned');
                }
            }

            sleep(3);

            $ilmStatus = $elastic->getIndexILMStatus($indexName);

            $currentIndexStatus = $ilmStatus['indices'][$indexName];

            if (isset($currentIndexStatus["phase"]) && $currentIndexStatus["phase"] != 'warm') {
                $elastic->moveIndexILMStep(
                    $indexName,
                    [
                        "phase" => $currentIndexStatus["phase"],
                        "action" => $currentIndexStatus["action"],
                        "name" => $currentIndexStatus["step"],
                    ],
                    [
                        "phase" => "warm",
                        "action" => "forcemerge",
                        "name" => "forcemerge",
                    ]
                );
            } else {
                if ($currentIndexStatus["phase"] == 'warm') {
                    dump('already warm');
                } else {
                    dump($currentIndexStatus);
                    dd('no phase set');
                }
            }

            $this->output->success('index moved successfully');

//
//            $indexName = $index['index'];
//            //dump('checking ' . $indexName);
//            $policy = $elastic->getIndexILMPolicy($indexName);
//
//            if (!isset($indicesCount[$policy ?? 'empty'])) {
//                $indicesCount[$policy ?? 'empty'] = 1;
//            } else {
//                $indicesCount[$policy ?? 'empty']++;
//            }

        }
        dump($indicesCount);
    }
}

