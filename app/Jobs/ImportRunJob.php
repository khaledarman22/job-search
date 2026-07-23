<?php

namespace App\Jobs;

use App\Models\ApifyRun;
use App\Services\Apify\RunImporter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ImportRunJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public ApifyRun $run) {}

    public function handle(RunImporter $importer): void
    {
        try {
            $importer->import($this->run);
        } catch (Throwable $e) {
            $this->run->error = $e->getMessage();
            $this->run->save();

            throw $e;
        }
    }
}
