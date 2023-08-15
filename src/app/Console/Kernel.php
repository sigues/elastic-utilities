<?php

namespace App\Console;

use App\Console\Commands\DeleteAndRestoreRedIndexes;
use App\Console\Commands\DeleteElasticIndexes;
use App\Console\Commands\FixingSkippedAliases;
use App\Console\Commands\MigrateElasticToLoki;
use App\Console\Commands\ReindexIndices;
use App\Console\Commands\ValidateAndAssignILMs;
use App\Console\Commands\ValidateElasticIndexes;
use App\Console\Commands\ValidateReindex;
use Illuminate\Console\Scheduling\Schedule;
use Laravel\Lumen\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * The Artisan commands provided by your application.
     *
     * @var array
     */
    protected $commands = [
        DeleteElasticIndexes::class,
        ValidateElasticIndexes::class,
        FixingSkippedAliases::class,
        DeleteAndRestoreRedIndexes::class,
        ValidateAndAssignILMs::class,
        ReindexIndices::class,
        ValidateReindex::class,
        MigrateElasticToLoki::class,
    ];

    /**
     * Define the application's command schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        //
    }
}
