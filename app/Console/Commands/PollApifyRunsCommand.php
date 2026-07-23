<?php

namespace App\Console\Commands;

use App\Enums\RunOrigin;
use App\Enums\RunPurpose;
use App\Jobs\ImportRunJob;
use App\Models\ApifyRun;
use App\Services\Apify\ApifyClient;
use App\Services\EnrichmentService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

class PollApifyRunsCommand extends Command
{
    protected $signature = 'apify:poll';

    protected $description = 'Poll active Apify runs, import finished ones';

    public function handle(ApifyClient $client): int
    {
        $runs = ApifyRun::where('origin', RunOrigin::System)
            ->whereIn('status', ApifyRun::ACTIVE_STATUSES)
            ->get();

        foreach ($runs as $run) {
            try {
                $this->pollRun($client, $run);
            } catch (Throwable $e) {
                // run واحد بايظ ميوقفش باقي الدورة
                $this->error("Run {$run->apify_run_id}: {$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }

    private function pollRun(ApifyClient $client, ApifyRun $run): void
    {
        $data = $client->getRun($run->apify_run_id);
        $status = $data['status'] ?? $run->status;

        $run->status = $status;
        if (! empty($data['finishedAt'])) {
            try {
                $run->finished_at = Carbon::parse($data['finishedAt']);
            } catch (Throwable) {
                // نتجاهل تاريخ غير صالح
            }
        }
        $run->usage_usd = $data['usageTotalUsd'] ?? $run->usage_usd;
        $run->default_dataset_id = $data['defaultDatasetId'] ?? $run->default_dataset_id;
        $run->default_kv_store_id = $data['defaultKeyValueStoreId'] ?? $run->default_kv_store_id;
        $run->save();

        if ($run->isSucceeded()) {
            if ($run->purpose === RunPurpose::Scrape) {
                ImportRunJob::dispatch($run);
                $this->info("Run {$run->apify_run_id} succeeded — import dispatched.");
            } elseif ($run->purpose === RunPurpose::Enrich) {
                try {
                    app(EnrichmentService::class)->importRun($run);
                    $this->info("Run {$run->apify_run_id} succeeded — enrichment imported.");
                } catch (Throwable $e) {
                    // الاستيراد المتزامن فشل — نطلّع الشركات من حالة running عشان
                    // ميفضلوش عالقين للأبد (الـ run مش هيتعاد بولّه بعد SUCCEEDED)
                    app(EnrichmentService::class)->handleFailedRun($run);
                    $run->forceFill(['error' => 'Enrichment import failed: '.$e->getMessage()])->save();
                    $this->error("Run {$run->apify_run_id} import failed: {$e->getMessage()}");
                }
            }

            return;
        }

        if ($run->isFinished()) {
            // FAILED / TIMED-OUT / ABORTED
            $run->error = 'Run '.$status;
            $run->save();

            if ($run->purpose === RunPurpose::Enrich) {
                app(EnrichmentService::class)->handleFailedRun($run);
            }

            $this->warn("Run {$run->apify_run_id} finished with status {$status}.");
        }
    }
}
