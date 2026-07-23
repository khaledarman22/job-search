<?php

namespace App\Console\Commands;

use App\Enums\OutreachStatus;
use App\Services\OutreachService;
use Illuminate\Console\Command;

class SendTickCommand extends Command
{
    protected $signature = 'outreach:send-tick';

    protected $description = 'نبضة الإرسال — يبعت الإيميل التالي في الطابور لو حان وقته';

    public function handle(OutreachService $outreach): int
    {
        $email = $outreach->sendNextIfDue();

        if ($email === null) {
            $this->info('no-op');
        } elseif ($email->status === OutreachStatus::Sent) {
            $this->info("sent to {$email->to_email}");
        } else {
            $this->warn("attempt failed for {$email->to_email} ({$email->status->value})");
        }

        return self::SUCCESS;
    }
}
