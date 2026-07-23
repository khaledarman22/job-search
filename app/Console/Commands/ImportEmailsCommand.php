<?php

namespace App\Console\Commands;

use App\Services\EmailListImporter;
use Illuminate\Console\Command;

class ImportEmailsCommand extends Command
{
    protected $signature = 'outreach:import-emails {file : path to a txt/csv file} {--no-queue}';

    protected $description = 'يستورد قائمة إيميلات من ملف كجهات اتصال، ويضيفهم اختياريًا لطابور الإرسال';

    public function handle(EmailListImporter $importer): int
    {
        $path = $this->argument('file');

        if (! is_file($path)) {
            $this->error("الملف غير موجود: {$path}");

            return self::FAILURE;
        }

        $content = (string) file_get_contents($path);

        $stats = $importer->import($content, ! $this->option('no-queue'));

        $this->table(
            ['found', 'imported', 'queued', 'invalid', 'duplicates'],
            [[$stats['found'], $stats['imported'], $stats['queued'], $stats['invalid'], $stats['duplicates']]],
        );

        return self::SUCCESS;
    }
}
