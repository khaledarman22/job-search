<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// الجدولة — أوامر الـ outreach بتتحكم بنفسها في البوابات (settings) جوّاها
Schedule::command('outreach:scrape --scheduled')->hourly();
Schedule::command('apify:poll')->everyMinute()->withoutOverlapping();
Schedule::command('apify:sync-history')->everySixHours();
// تحويل أسماء الشركات لدومينات حقيقية (مجاني) — يغذّي الإثراء بعدها
Schedule::command('outreach:resolve-domains')->everyTenMinutes()->withoutOverlapping();
Schedule::command('outreach:enrich')->everyTenMinutes()->withoutOverlapping();
Schedule::command('outreach:queue-fill')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('outreach:send-tick')->everyMinute()->withoutOverlapping();
Schedule::command('queue:work --stop-when-empty --max-time=50')->everyMinute()->withoutOverlapping();
