<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->boolean('is_encrypted')->default(false);
            $table->timestamps();
        });

        Schema::create('sources', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('actor_id');
            $table->json('input_template');
            $table->json('field_map');
            $table->string('default_keywords')->nullable();
            $table->string('default_location')->nullable();
            $table->unsignedInteger('max_items')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamp('last_run_at')->nullable();
            $table->timestamps();
        });

        Schema::create('apify_runs', function (Blueprint $table) {
            $table->id();
            $table->string('apify_run_id')->unique();
            $table->string('actor_id');
            $table->string('actor_name')->nullable();
            $table->foreignId('source_id')->nullable()->constrained()->nullOnDelete();
            $table->string('purpose')->default('external');
            $table->string('origin')->default('synced');
            $table->string('status');
            $table->json('input')->nullable();
            $table->string('default_dataset_id')->nullable();
            $table->string('default_kv_store_id')->nullable();
            $table->unsignedInteger('item_count')->nullable();
            $table->decimal('usage_usd', 8, 4)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('imported_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('started_at');
            $table->index('purpose');
        });

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('normalized_name')->unique();
            $table->string('website')->nullable();
            $table->string('domain')->nullable()->index();
            $table->string('location')->nullable();
            $table->string('enrichment_status')->default('pending');
            $table->foreignId('enrich_run_id')->nullable()->constrained('apify_runs')->nullOnDelete();
            $table->timestamp('enriched_at')->nullable();
            $table->timestamps();

            $table->index('enrichment_status');
        });

        Schema::create('job_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('apify_run_id')->nullable()->constrained()->nullOnDelete();
            $table->string('external_id')->nullable();
            $table->text('url');
            $table->char('url_hash', 40);
            $table->string('title');
            $table->string('location')->nullable();
            $table->string('salary')->nullable();
            $table->longText('description')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->json('raw')->nullable();
            $table->string('status')->default('new');
            $table->timestamps();

            $table->unique(['source_id', 'url_hash']);
            $table->index('status');
        });

        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('job_post_id')->nullable()->constrained()->nullOnDelete();
            $table->string('email')->nullable()->unique();
            $table->string('phone')->nullable();
            $table->string('name')->nullable();
            $table->string('position')->nullable();
            $table->string('discovered_via')->default('enrichment');
            $table->boolean('is_alternate')->default(false);
            $table->string('status')->default('new');
            $table->timestamps();

            $table->index('status');
        });

        Schema::create('contact_job_sightings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->foreignId('job_post_id')->constrained()->cascadeOnDelete();
            $table->timestamp('seen_at');
            $table->timestamps();

            $table->unique(['contact_id', 'job_post_id']);
        });

        Schema::create('outreach_emails', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('job_post_id')->nullable()->constrained()->nullOnDelete();
            $table->string('to_email');
            $table->text('subject')->nullable();
            $table->longText('body_html')->nullable();
            $table->string('status')->default('queued');
            $table->boolean('is_manual')->default(false);
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->text('error')->nullable();
            $table->char('tracking_token', 40)->unique();
            $table->unsignedInteger('position')->default(0);
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('last_opened_at')->nullable();
            $table->unsignedInteger('open_count')->default(0);
            $table->string('smtp_message_id')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('to_email');
            $table->index('sent_at');
        });

        Schema::create('email_opens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('outreach_email_id')->constrained()->cascadeOnDelete();
            $table->timestamp('opened_at');
            $table->string('ip', 45)->nullable();
            $table->text('user_agent')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_opens');
        Schema::dropIfExists('outreach_emails');
        Schema::dropIfExists('contact_job_sightings');
        Schema::dropIfExists('contacts');
        Schema::dropIfExists('job_posts');
        Schema::dropIfExists('companies');
        Schema::dropIfExists('apify_runs');
        Schema::dropIfExists('sources');
        Schema::dropIfExists('settings');
    }
};
