<?php
declare(strict_types=1);
declare(ticks = 1);

namespace App\Console\Commands;

use App\Contracts\SearchEngineInterface;
use App\Services\ES2LokiSyncTracker;
use App\Services\LokiClient;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

function signal_handler($signal): void {
    switch($signal) {
        case SIGTERM:
            MigrateElasticToLoki::setTerminate(true);
            print "Caught SIGTERM\n";
        case SIGKILL:
            MigrateElasticToLoki::setTerminate(true);
            print "Caught SIGKILL\n";
        case SIGINT:
            MigrateElasticToLoki::setTerminate(true);
            print "Caught SIGINT\n";
    }
}

\pcntl_signal(SIGTERM, function() {
    signal_handler(SIGTERM);
});

\pcntl_signal(SIGINT, function() {
    signal_handler(SIGINT);
});

class MigrateElasticToLoki extends Command
{
    public static bool $terminate = false;
    /**
     * @var string
     */
    protected $signature   = 'migration:elastic-to-loki';

    /**
     * @var string
     */
    protected $description = 'Merge the monthly indices';

    protected ?string $type = '';

    public const BATCHES_TO_SYNC = 400;
    /**
     * @var null
     */
    private $timestamp;

    public function __construct(
        protected SearchEngineInterface $elastic,
        protected LokiClient $loki,
        protected ES2LokiSyncTracker $syncTracker,
    ) {
        parent::__construct();
    }

    public function handle(): void
    {
        foreach (range(0, self::BATCHES_TO_SYNC) as $batch) {
            dump('syncing batch number ' . $batch);
            $this->syncBatch();
            dump('batch ' . $batch . ' finished');
        }
    }

    public static function setTerminate(bool $value): void
    {
        self::$terminate = $value;
    }

    protected function syncBatch()
    {
        // Gets 1000 documents
        $documentsBatch = $this->getNextDocumentsBatch();
        $batch = [];
        foreach ($documentsBatch['documents'] as $document) {
            $labels = $this->getLabels($document);

            $dateTime = $document['_source']['date_time'];
            $carbonTime = Carbon::parse($dateTime);

            $response = $this->loki->push([
                [(string) ($carbonTime->timestamp * 1000 * 1000 * 1000), json_encode($document['_source'])]
            ], $labels);

            if ($response !== 204) {
                dump($response);
                dump('sync failed');
                exit;
            } else {
                $this->syncTracker->persist($documentsBatch['index'], $carbonTime, $this->type);
            }

            if (self::$terminate === true) {
                dump('finishing gracefully');
                exit;
            }
        }
    }

    /**
     * Getting labels to send to the new loki endpoint /loki/api/v1/push
     *
     * @param $document
     * @return array
     */
    private function getLabels($document): array
    {
        $labels = [
            'event_type' => $document['_source']['event_type'] ,
            'type' => $this->type,
        ];

        if (isset($document['_source']['active_user'])) {
            $labels['active_user_id'] = (string) $document['_source']['active_user']['id'];
            $labels['figured_staff'] = ($document['_source']['active_user']['figured_staff'] ? 'true' : 'false');

            if ($document['_source']['active_user']['orgs']) {
                $orgs = Arr::where($document['_source']['active_user']['orgs'], function($item) {
                    return $item['name'] !== 'Figured Support';
                });

                if ($orgs) {
                    $org = array_shift($orgs);
                    $labels['active_org_name'] = $org['name'];
                    $labels['active_org_id'] = $org['id'];
                }
            }
        }

        if (isset($document['_source']['active_farm']) && is_array($document['_source']['active_farm'])) {
            $labels['active_farm_id'] = (string) $document['_source']['active_farm']['id'];
            $labels['country_code'] = $document['_source']['active_farm']['country_code'];
            $labels['farm_demo'] = ($document['_source']['active_farm']['demo'] ? 'true' : 'false');
        }

        if (isset($document['_source']['http']) && is_array($document['_source']['http'])) {
            $labels['http_method'] = $document['_source']['http']['method'];
        }

        return $labels;
    }

    /**
     * Getting labels to send to the old loki endpoint /api/prom/push (deprecated)
     *
     * @param $document
     * @return array
     */
    private function getLabelsOld($document): array
    {
        $labels = [
            'event_type="' . $document['_source']['event_type'] . '"',
            'type="' . $this->type . '"',
        ];

        if (isset($document['_source']['active_user'])) {
            $labels[] = 'active_user_id="' . $document['_source']['active_user']['id'] . '"';
            $labels[] = 'figured_staff="' . ($document['_source']['active_user']['figured_staff'] ? 'true' : 'false') . '"';

            if ($document['_source']['active_user']['orgs']) {
                $orgs = Arr::where($document['_source']['active_user']['orgs'], function($item) {
                    return $item['name'] !== 'Figured Support';
                });

                if ($orgs) {
                    $org = array_shift($orgs);
                    $labels[] = 'active_org_name="' . $org['name'] . '"';
                    $labels[] = 'active_org_id="' . $org['id'] . '"';
                }
            }
        }

        if (isset($document['_source']['active_farm']) && is_array($document['_source']['active_farm'])) {
            $labels[] = 'active_farm_id="' . $document['_source']['active_farm']['id'] . '"';
            $labels[] = 'country_code="' . $document['_source']['active_farm']['country_code'] . '"';
            $labels[] = 'farm_demo="' . ($document['_source']['active_farm']['demo'] ? 'true' : 'false') . '"';
        }

        if (isset($document['_source']['http']) && is_array($document['_source']['http'])) {
            $labels[] = 'http_method="' . $document['_source']['http']['method'] . '"';
        }

        return $labels;
    }

    private function getNextDocumentsBatch(): array
    {
        $found                  = false;
        $lastSyncStatus         = $this->syncTracker->getLastTrackerStatus();
        $this->timestamp        = (isset($lastSyncStatus['timestamp'])) ? $lastSyncStatus['timestamp']->toIso8601String() : null;
        $nextIndex              = $lastSyncStatus['index'] ?? null;
        $this->type             = $lastSyncStatus['type'] ?? null;
        $tryIndexFromSyncStatus = isset($lastSyncStatus['index']);

        while ($found == false) {
            if (!$tryIndexFromSyncStatus) {
                $nextIndex = $this->getNextIndex($nextIndex);
            }
            $tryIndexFromSyncStatus = false;

            Log::info('$nextIndex ' . $nextIndex . ' > ' . $this->timestamp);

            try {
                $documents = $this->elastic->getDocumentsFromIndex($nextIndex, 1000, $this->timestamp);
            } catch (\Exception $e) {
                dump('error when fetching docs');
                dump($e->getMessage());

                exit;
            }

            if ($documents) {
                $found = true;
            }
        }

        return [
            'index'     => $nextIndex,
            'documents' => $documents,
        ];
    }

    private function getNextIndex(string $lastIndex = null): string
    {
        $types = [
            'events',
            'requests',
            'api_requests',
        ];

        $periods = [
            '2018' => range(10, 12),
            '2019' => range(1, 12),
            '2020' => range(1, 12),
            '2021' => range(1, 12),
            '2022' => range(1, 12),
            '2023' => range(2, 6),
        ];

        $mergedString = '-merged-';

        if ($lastIndex) {
            $indexPieces = explode('-', $lastIndex);
            $type  = $indexPieces[0];
            $dateString = $indexPieces[1] == 'merged' ? $indexPieces[2] : $indexPieces[1];
            $date  = explode('.', $dateString);
            $year  = (int) $date[0];
            $month = (int) $date[1];
            $monthIndex = array_search($month, $periods[$year]);

            if (isset($periods[$year][$monthIndex + 1])) {
                // If a next month exists within the same year period, get next month
                $nextIndex = $type . $mergedString . $year . '.' . str_pad((string) $periods[$year][$monthIndex + 1], 2, '0', STR_PAD_LEFT);
            } elseif (isset($periods[$year + 1]) && isset($periods[$year + 1][$periods[$year + 1][0]])) {
                // If a next month doean't exists, get next year but first month
                $nextIndex = $type . $mergedString . ($year + 1) . '.' . str_pad((string) $periods[$year + 1][0], 2, '0', STR_PAD_LEFT);
            } else {
                // if next year doesn't exist, get next type
                $arrayTypeIndex = array_search($type, $types);

                if (!isset($types[$arrayTypeIndex + 1])) {
                    dump('no more types');
                    exit;
                }
                $type            = $types[$arrayTypeIndex + 1];
                $this->timestamp = null;
                $nextIndex       = $type . $mergedString . array_key_first($periods) . '.' . str_pad((string) $periods[array_key_first($periods)][0], 2, '0', STR_PAD_LEFT);
            }
        } else {
            // If the lastIndex wasn't saved yet
            $type  = $types[0];
            $year  = array_key_first($periods);
            $month = $periods[$year][0];

            $nextIndex = $type . $mergedString . $year . '.' . str_pad((string) $month, 2, '0', STR_PAD_LEFT);
        }

        $this->type = $type;

        return $nextIndex;
    }

}
