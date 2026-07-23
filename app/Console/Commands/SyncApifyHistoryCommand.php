<?php

namespace App\Console\Commands;

use App\Enums\RunOrigin;
use App\Enums\RunPurpose;
use App\Models\ApifyRun;
use App\Services\Apify\ApifyClient;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

class SyncApifyHistoryCommand extends Command
{
    private const PAGE_SIZE = 1000;

    protected $signature = 'apify:sync-history {--full}';

    protected $description = 'Sync the Apify account run history into apify_runs';

    public function handle(ApifyClient $client): int
    {
        if (! $client->isConfigured()) {
            $this->warn('Apify token not configured.');

            return self::SUCCESS;
        }

        $startedAfter = null;
        if (! $this->option('full')) {
            $max = ApifyRun::max('started_at');
            $startedAfter = $max ? Carbon::parse($max)->toIso8601String() : null;
        }

        $synced = 0;
        $offset = 0;

        do {
            $page = $client->listAccountRuns($offset, self::PAGE_SIZE, true, $startedAfter);
            $items = $page['items'] ?? [];
            $newInPage = false;

            foreach ($items as $item) {
                $runId = $item['id'] ?? null;
                if (! $runId) {
                    continue;
                }

                $existing = ApifyRun::where('apify_run_id', $runId)->first();

                if ($existing) {
                    // نحدّث الحالة فقط — origin/purpose/source_id ملك النظام ومنلمسهمش
                    $existing->status = $item['status'] ?? $existing->status;
                    $existing->finished_at = $this->parseDate($item['finishedAt'] ?? null) ?? $existing->finished_at;
                    $existing->usage_usd = $item['usageTotalUsd'] ?? $existing->usage_usd;
                    $existing->default_dataset_id = $item['defaultDatasetId'] ?? $existing->default_dataset_id;
                    $existing->default_kv_store_id = $item['defaultKeyValueStoreId'] ?? $existing->default_kv_store_id;
                    $existing->save();
                } else {
                    ApifyRun::create([
                        'apify_run_id' => $runId,
                        'actor_id' => (string) ($item['actId'] ?? ''),
                        'purpose' => RunPurpose::External,
                        'origin' => RunOrigin::Synced,
                        'status' => $item['status'] ?? 'UNKNOWN',
                        'started_at' => $this->parseDate($item['startedAt'] ?? null),
                        'finished_at' => $this->parseDate($item['finishedAt'] ?? null),
                        'default_dataset_id' => $item['defaultDatasetId'] ?? null,
                        'default_kv_store_id' => $item['defaultKeyValueStoreId'] ?? null,
                        'usage_usd' => $item['usageTotalUsd'] ?? null,
                    ]);
                    $synced++;
                    $newInPage = true;
                }
            }

            $offset += self::PAGE_SIZE;
            $more = count($items) === self::PAGE_SIZE && ($this->option('full') || $newInPage);
        } while ($more);

        $this->fillItemCounts($client);
        $this->fillActorNames($client);

        $this->info("Synced {$synced} new run(s).");

        return self::SUCCESS;
    }

    /** أحدث 50 run لسه ملهمش item_count — نجيبه من الـ dataset */
    private function fillItemCounts(ApifyClient $client): void
    {
        $runs = ApifyRun::whereNotNull('default_dataset_id')
            ->whereNull('item_count')
            ->orderByDesc('started_at')
            ->limit(50)
            ->get();

        foreach ($runs as $run) {
            try {
                $dataset = $client->getDataset($run->default_dataset_id);
                if (isset($dataset['itemCount'])) {
                    $run->item_count = (int) $dataset['itemCount'];
                    $run->save();
                }
            } catch (Throwable) {
                continue;
            }
        }
    }

    private function fillActorNames(ApifyClient $client): void
    {
        $actorIds = ApifyRun::whereNull('actor_name')
            ->where('actor_id', '!=', '')
            ->distinct()
            ->pluck('actor_id');

        $cache = [];

        foreach ($actorIds as $actorId) {
            if (! array_key_exists($actorId, $cache)) {
                try {
                    $actor = $client->getActor($actorId);
                    $username = (string) ($actor['username'] ?? '');
                    $name = (string) ($actor['name'] ?? '');
                    $cache[$actorId] = trim("{$username}/{$name}", '/') ?: null;
                } catch (Throwable) {
                    $cache[$actorId] = null;
                }
            }

            if ($cache[$actorId] !== null) {
                ApifyRun::where('actor_id', $actorId)
                    ->whereNull('actor_name')
                    ->update(['actor_name' => $cache[$actorId]]);
            }
        }
    }

    private function parseDate(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
