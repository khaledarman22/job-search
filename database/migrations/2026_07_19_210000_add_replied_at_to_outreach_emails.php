<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outreach_emails', function (Blueprint $table) {
            // «تم الرد» — يدوي من الداشبورد لحد ما يتضاف كشف الردود عبر IMAP
            $table->timestamp('replied_at')->nullable()->after('open_count');
        });
    }

    public function down(): void
    {
        Schema::table('outreach_emails', function (Blueprint $table) {
            $table->dropColumn('replied_at');
        });
    }
};
