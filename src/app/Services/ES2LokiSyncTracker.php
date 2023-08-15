<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\File;

class ES2LokiSyncTracker
{
    private $filePath = 'storage/tracking/status.json';

    public function persist(string $index, Carbon $timestamp, string $type)
    {
        $values = [
            'timestamp' => $timestamp->toISOString(),
            'index'     => $index,
            'type'      => $type,
        ];

        $jsonString = json_encode($values, JSON_PRETTY_PRINT);

        File::put($this->filePath, $jsonString);
    }

    public function getLastTrackerStatus(): ?array
    {
        $json = json_decode(file_get_contents($this->filePath), true);

        if (!$json) {
            return null;
        }

        // Returning the timestamp as a carbon instance
        dump($json['timestamp']);
        $json['timestamp'] = Carbon::parse($json['timestamp']);

        return $json;
    }
}
