<?php

namespace App\Services;

use App\Contracts\SearchEngineInterface;
use Elasticsearch\Client;
use Elasticsearch\ClientBuilder;
use Exception;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

class ElasticSearchClient implements SearchEngineInterface
{
    private ?Client $client = null;

    public function __construct()
    {
        $this->client();
    }

    public function client(): Client
    {
        if (!$this->client) {
            $this->client = ClientBuilder::create()
                ->setElasticCloudId(env('ELASTIC_CLOUD_ID'))
                ->setApiKey(env('ELASTIC_API_KEY_ID'), env('ELASTIC_API_KEY'))
                ->build();
        }

        return $this->client;
    }

    public function getIndex($index): array
    {
        return $this->client()->cat()->indices([
            'index' => $index,
        ]);
    }

    public function getAliases($index): array
    {
        $indices = $this->client()->indices();
        return $indices->getAlias(
            ['index' => $index]
        );
    }

    public function getSnapshots(string $filter = '*'): array
    {
        return $this->client()->snapshot()->get([
            'repository' => '*',
            'snapshot' => $filter,
        ])['snapshots'];
    }

    public function getLatestSnapshotForAlias(string $alias = '*'): array|bool
    {
        $snapshots       = $this->getSnapshots('*' . $alias . '*');
        $snapshotOldDate = 0;
        $newestSnapshot  = false;
        foreach ($snapshots as $snapshot) {
            $snapshotDate = preg_replace('/[^0-9]/', '', explode($alias, $snapshot['snapshot'])[0]);
            if (stripos($snapshot['snapshot'], $alias)
                && $snapshotDate > $snapshotOldDate
                && $snapshot['shards']['failed'] == 0
            ) {
                $newestSnapshot = $snapshot;

                $snapshotOldDate = $snapshotDate;
            }
        }

        return $newestSnapshot;
    }

    /**
     * @param string $index
     * @param string $alias
     * @param bool $debug
     * @return array
     */
    public function removeAlias(string $index, string $alias, bool $debug = false): bool
    {
        $request = [
            'body' => [
                'actions' => [
                    'remove' => [
                        'index' => $index,
                        'alias' => $alias,
                    ],
                ],
            ],
        ];

        if ($debug) {
            dump($request);
        }

        //todo: convert response in boolean based in acknowledged == true
        $response = $this->client()->indices()->updateAliases($request);
        return $response['acknowledged'];
    }

    /**
     * @param string $snapshot
     * @param string $repository
     * @param string $index
     * @return array
     */
    public function restoreIndexFromSnapshot(string $snapshot, string $repository, string $index, bool $debug = false): bool
    {
        $request = [
            'snapshot'   => $snapshot,
            'repository' => $repository,
            'body'       => [
                'indices' => $index,
                'ignore_unavailable' => true,
                'include_global_state' => false,
            ]
        ];

        if ($debug) {
            dump('restore request');
            dump($request);
        }

        $response = $this->client()->snapshot()->restore($request);

        Log::info($response);

        return $response['accepted'] ?? false;
    }

    public function count(string $index): int
    {
        $request = [
            'index' => $index,
        ];

        return $this->client()->count($request)['count'];
    }

    public function deleteIndex(string $id, string $index): array
    {
        return $this->client()->indices()->delete([
            'index' => $index,
        ]);
    }

    public function isILMStopped(): bool
    {
        $response = $this->client->ilm()->getStatus();

        return ($response['operation_mode'] === 'STOPPED') ? true : false;
    }

    public function disableILM(): bool
    {
        $response = $this->client->ilm()->stop();

        return $response['acknowledged'];
    }

    public function enableILM(): bool
    {
        $response = $this->client->ilm()->start();

        return $response['acknowledged'];
    }

    public function getIndexILMPolicy(string $indexName): ?string
    {
        $response = $this->client->indices()->getSettings([
            'index' => $indexName,
            'name' => 'index.lifecycle.name'
        ]);

        if (!isset($response[$indexName])) {
            dump('no index');
            dump($response);

            return null;
        }

        $lifecycleName = Arr::get($response[$indexName],  'settings.index.lifecycle.name');

        if (!empty($lifecycleName) && $lifecycleName != '_none') {
            return $lifecycleName;
        } else {
            return null; // Index does not have an ILM policy
        }
    }

    public function assignILMPolicyToIndex(string $indexName, string $ilmPolicyName): bool
    {
        $body = [
            'index' => $indexName,
            'body' => [
                'settings' => [
                    'index' => [
                        'lifecycle' => [
                            'name' => $ilmPolicyName,
                        ],
                    ],
                ],
            ],
        ];

        $response = $this->client()->indices()->putSettings($body);

        return $response['acknowledged'];
    }

    public function reindex(array $source, string $destiny): string
    {
        $body = [
            'wait_for_completion' => false,
            'refresh' => true,
            'body' => [
                'source' => [
                    'index' => $source,
                ],
                'dest' => [
                    'index' => $destiny
                ]
            ],
        ];

        $response = $this->client()->reindex($body);

        return $response['task'];
    }

    public function getIndexILMStatus(string $index): array
    {
        $response = $this->client->ilm()->explainLifecycle([
            'index' => $index
        ]);

        return $response;
    }

    public function moveIndexILMStep(string $index, array $currentStep, array $nextStep): bool
    {
        $response = $this->client()->ilm()->moveToStep([
            'index' => $index,
            'body' => [
                'current_step' => $currentStep,
                'next_step' => $nextStep,
            ]
        ]);
        dump($response);

        return $response['acknowledged'];
    }

    public function retryILM($index)
    {
        $response = $this->client()->ilm()->retry([
            'index' => $index,
        ]);

        dd($response);
    }

    public function getDocumentsFromIndex(string $index, int $limit = 10, ?string $timestamp = null): array
    {
        $query = [];
        if ($timestamp) {
            $query['range'] = [
                'date_time' => [
                    'gt' => $timestamp,
                ],
            ];
        } else {
            $query['match_all'] = new \stdClass();
        }

        // Define the search parameters
        $params = [
            'index' => $index,
            'size'  => $limit,
            'body' => [
                'query' => $query,
                'sort' => [
                    'date_time' => ['order' => 'asc'],
                ],
            ],
        ];

        try {
            // Send the search request to Elasticsearch
            $response = $this->client()->search($params);

            // Retrieve the documents from the response
            $documents = $response['hits']['hits'];
        } catch (Exception $e) {
            // Handle any exceptions
            echo "Error: " . $e->getMessage();
            $documents = [];
        }

        return $documents;
    }
}
