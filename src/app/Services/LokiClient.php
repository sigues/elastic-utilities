<?php
declare(strict_types=1);

namespace App\Services;

class LokiClient
{
    public function __construct(
        protected ?string $url = null,
        protected array $headers = [
            'Content-Type: application/json'
        ]
    ) {
        if (!$this->url) {
            $this->url = env('LOKI_PUSH_URL');//'http://gateway.docker.internal:8080/api/prom/push'
        }
    }

    public function push(array $entries, $labels): int
    {
        $ch = curl_init();

        $data = [
            'streams' => [
                [
                    'stream' => $labels,
                    'values' => $entries
                ]
            ]
        ];

        $options = [
            CURLOPT_URL => $this->url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $this->headers,
            CURLOPT_HEADER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
        ];

        curl_setopt_array($ch, $options);

        $response = curl_exec($ch);

        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        //dump($httpcode);

        curl_close($ch);

        return $httpcode;
    }
}
