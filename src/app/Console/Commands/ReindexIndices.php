<?php

namespace App\Console\Commands;

use App\Contracts\SearchEngineInterface;
use Illuminate\Console\Command;

class ReindexIndices extends Command
{

    /**
     * @var string
     */
    protected $signature   = 'elastic:reindex-monthly-indices';

    /**
     * @var string
     */
    protected $description = 'Merge the monthly indices';

    public function handle(SearchEngineInterface $elastic): void
    {
        $elastic->disableILM();

        $types = [
//            'requests',
            'api_requests',
            'events',
        ];

        $periods = [
//            '2018' => range(10, 12),
//            '2019' => range(1, 12),
//            '2020' => range(1, 12),
            //'2021' => range(1, 7),
//            '2021' => [7],


            //'2021' => range(8, 9),
//            '2022' => range(1, 3),
//            '2023' => range(1, 4),
            '2023' => [3],
        ];

        foreach ($types as $type) {
            foreach ($periods as $year => $months) {
                foreach ($months as $month) {
                    $source = [$type . '-' . $year . '.' . str_pad($month, 2, '0', STR_PAD_LEFT) . '.*',];

                    if ($type === 'requests') {
                        $source = [
                            $type . '-' . $year . '.' . str_pad($month, 2, '0', STR_PAD_LEFT) . '.*',
                            $type . '-split-' . $year . '-' . str_pad($month, 2, '0', STR_PAD_LEFT) . '-*',
                        ];
                    }

                    $dest = $type . '-merged-' . $year . '.' . str_pad($month, 2, '0', STR_PAD_LEFT);
                    //dump('creating: ' . $dest);
                    $response = $elastic->reindex($source, $dest);
                    dump($response);
                }
            }
        }
    }
}
