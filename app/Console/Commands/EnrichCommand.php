<?php

namespace App\Console\Commands;

use App\Services\ApifySpendTracker;
use App\Services\EnrichmentService;
use Illuminate\Console\Command;

class EnrichCommand extends Command
{
    protected $signature = 'outreach:enrich';

    protected $description = 'Start a contact-enrichment batch on Apify for pending companies';

    public function handle(EnrichmentService $service, ApifySpendTracker $spend): int
    {
        // الإثراء بيكلّف ~$0.50 للتشغيلة — نفس بوابة الإنفاق بتاعة الاسكراب
        if (! $spend->allowScrape()) {
            $reason = $spend->state()['paused_reason']
                ?? 'تجاوز حد إنفاق Apify.';

            $this->warn('الإثراء متوقف: '.$reason.' غيّر حساب/توكن Apify لاستئنافه.');

            return self::SUCCESS;
        }

        $run = $service->startBatch();

        if ($run === null) {
            $this->line('nothing to enrich');

            return self::SUCCESS;
        }

        $this->info("Started enrichment run: {$run->apify_run_id}");

        return self::SUCCESS;
    }
}
