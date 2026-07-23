<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            // وقت آخر محاولة لاستنتاج الدومين — يمنع إعادة المحاولة في لوب على نفس الشركة
            $table->timestamp('domain_checked_at')->nullable()->after('domain');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('domain_checked_at');
        });
    }
};
