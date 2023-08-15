<?php

namespace App\Contracts;

use Elasticsearch\Client;

interface SearchEngineInterface
{
    public function client(): Client;

    public function getAliases($index): array;

    public function getIndex($index): array;

    public function getSnapshots(string $index = '*'): array;

    public function getLatestSnapshotForAlias(string $alias = '*'): array|bool;

    public function removeAlias(string $index, string $alias, bool $debug = false): bool;

    public function restoreIndexFromSnapshot(string $snapshot, string $repository, string $index, bool $debug = false): bool;

    public function count(string $index): int;

    public function deleteIndex(string $id, string $index): array;

    public function isILMStopped(): bool;

    public function disableILM(): bool;

    public function enableILM(): bool;

    public function getIndexILMPolicy(string $indexName): ?string;

    public function assignILMPolicyToIndex(string $indexName, string $ilmPolicyName): bool;

    public function reindex(array $source, string $destiny): string;

    public function getIndexILMStatus(string $index): array;

    public function moveIndexILMStep(string $index, array $currentPhase, array $nextPhase): bool;

    public function retryILM($index);

    public function getDocumentsFromIndex(string $index, int $limit = 10, ?string $timestamp = null): array;

}
