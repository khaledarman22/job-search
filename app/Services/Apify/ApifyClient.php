<?php

namespace App\Services\Apify;

use App\Exceptions\ApifyException;
use App\Support\SettingsRepository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

class ApifyClient
{
    private const BASE_URL = 'https://api.apify.com/v2';

    public function __construct(private SettingsRepository $settings) {}

    public function isConfigured(): bool
    {
        return $this->settings->has('apify_token');
    }

    public function me(): array
    {
        return $this->getJson('/users/me', [], 'users/me')['data'] ?? [];
    }

    public function startActor(string $actorId, array $input, array $query = []): array
    {
        // بدون retry — إعادة المحاولة على POST ممكن تعمل run مكرر
        $path = '/actors/'.$this->pathId($actorId).'/runs';
        if ($query !== []) {
            $path .= '?'.http_build_query($query);
        }

        $response = $this->request(retry: false)->post($path, $input);

        if (! $response->successful()) {
            throw ApifyException::fromResponse($response, "start actor {$actorId}");
        }

        return $response->json('data') ?? [];
    }

    public function getRun(string $runId): array
    {
        return $this->getJson("/actor-runs/{$runId}", [], "get run {$runId}")['data'] ?? [];
    }

    public function listAccountRuns(int $offset = 0, int $limit = 1000, bool $desc = true, ?string $startedAfter = null): array
    {
        $query = [
            'offset' => $offset,
            'limit' => $limit,
            'desc' => $desc ? 'true' : 'false',
        ];
        if ($startedAfter !== null) {
            $query['startedAfter'] = $startedAfter;
        }

        $data = $this->getJson('/actor-runs', $query, 'list account runs')['data'] ?? [];

        return [
            'items' => $data['items'] ?? [],
            'total' => (int) ($data['total'] ?? 0),
            'count' => (int) ($data['count'] ?? count($data['items'] ?? [])),
        ];
    }

    public function getDatasetItems(string $datasetId, int $offset = 0, int $limit = 500): array
    {
        $json = $this->getJson("/datasets/{$datasetId}/items", [
            'format' => 'json',
            'clean' => 'true',
            'offset' => $offset,
            'limit' => $limit,
        ], "get dataset items {$datasetId}");

        return is_array($json) ? $json : [];
    }

    public function getDataset(string $datasetId): array
    {
        return $this->getJson("/datasets/{$datasetId}", [], "get dataset {$datasetId}")['data'] ?? [];
    }

    public function getRunInput(string $kvStoreId): ?array
    {
        $response = $this->request()->get("/key-value-stores/{$kvStoreId}/records/INPUT");

        if ($response->status() === 404) {
            return null;
        }
        if (! $response->successful()) {
            throw ApifyException::fromResponse($response, "get run input {$kvStoreId}");
        }

        $json = $response->json();

        return is_array($json) ? $json : null;
    }

    public function getActor(string $actorId): array
    {
        return $this->getJson('/actors/'.$this->pathId($actorId), [], "get actor {$actorId}")['data'] ?? [];
    }

    /** actor ids بصيغة "user/name" لازم تتحول لـ "user~name" في الـ URL */
    private function pathId(string $actorId): string
    {
        return str_replace('/', '~', $actorId);
    }

    private function getJson(string $path, array $query, string $context): array
    {
        $response = $this->request()->get($path, $query);

        if (! $response->successful()) {
            throw ApifyException::fromResponse($response, $context);
        }

        return $response->json() ?? [];
    }

    private function request(bool $retry = true): PendingRequest
    {
        $token = $this->settings->get('apify_token');
        if ($token === null || $token === '') {
            throw new ApifyException('Apify token not configured');
        }

        $request = Http::baseUrl(self::BASE_URL)
            ->withToken($token)
            ->acceptJson()
            ->timeout(30);

        if ($retry) {
            // نعيد المحاولة على أخطاء الاتصال و 5xx فقط، وبدون رمي RequestException
            $request = $request->retry(2, 500, function (\Throwable $e): bool {
                return $e instanceof ConnectionException
                    || ($e instanceof RequestException && $e->response->serverError());
            }, throw: false);
        }

        return $request;
    }
}
