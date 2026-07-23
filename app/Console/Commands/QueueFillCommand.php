<?php

namespace App\Console\Commands;

use App\Services\OutreachService;
use App\Support\SettingsRepository;
use Illuminate\Console\Command;

class QueueFillCommand extends Command
{
    protected $signature = 'outreach:queue-fill';

    protected $description = 'يملأ طابور الإرسال من جهات الاتصال الجديدة الصالحة';

    public function handle(OutreachService $outreach, SettingsRepository $settings): int
    {
        // الملء التلقائي فقط هو المحكوم بالإعداد — الإدراج اليدوي عبر الـ API يفضل شغّال
        if (! $settings->getBool('auto_queue_enabled', true)) {
            $this->info('auto-queue disabled — skipped.');

            return self::SUCCESS;
        }

        $count = $outreach->fillQueue();

        $this->info("queued: {$count}");

        return self::SUCCESS;
    }
}
