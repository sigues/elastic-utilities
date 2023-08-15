<?php

namespace App\Console\Commands;

use App\Contracts\SearchEngineInterface;
use Illuminate\Console\Command;

class ValidateReindex extends Command
{

    /**
     * @var string
     */
    protected $signature   = 'elastic:validate-reindex';

    /**
     * @var string
     */
    protected $description = 'Merge the monthly indices';

    public function handle(SearchEngineInterface $elastic): void
    {
        $elastic->disableILM();

        $types = [
            'requests',
            'api_requests',
            'events',
        ];

        $periods = [

            '2018' => range(9, 12),
            '2019' => range(1, 12),
            '2020' => range(1, 12),
            '2021' => range(1, 12),
            '2022' => range(1, 12),
            '2023' => range(1, 4),
        ];

        foreach ($types as $type) {
            foreach ($periods as $year => $months) {
                foreach ($months as $month) {
                    $sources = [$type . '-' . $year . '.' . str_pad($month, 2, '0', STR_PAD_LEFT) . '.*',];

                    if ($type === 'requests') {
                        $sources = [
                            $type . '-' . $year . '.' . str_pad($month, 2, '0', STR_PAD_LEFT) . '.*',
                            $type . '-split-' . $year . '-' . str_pad($month, 2, '0', STR_PAD_LEFT) . '-*',
                        ];
                    }

                    $countOriginal = 0;
                    $countNew = 0;
                    foreach ($sources as $source) {
                        try {
                            $countOriginal += $elastic->count($source);
                        } catch (\Exception $e) {
                            dump($source . ' not found');
                        }
                    }

                    $dest = $type . '-merged-' . $year . '.' . str_pad($month, 2, '0', STR_PAD_LEFT);

                    try {
                        $countNew = $elastic->count($dest);
                    } catch (\Exception $e) {
                        dump($dest . ' not found');
                    }

                    if ($countNew === $countOriginal) {
                        $this->output->success($type . '-' . $year . '-' . str_pad($month, 2, '0', STR_PAD_LEFT) . ' matches!');
                    } else {
                        dump('original: ' . $countOriginal . ' vs new: ' . $countNew);
                        dd($type . '-' . $year . '-' . str_pad($month, 2, '0', STR_PAD_LEFT) . ' failed!');
                    }
                }
            }
        }
    }
}
