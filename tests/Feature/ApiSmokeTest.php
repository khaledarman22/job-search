<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\SettingsRepository;
use Database\Seeders\AdminUserSeeder;
use Database\Seeders\SourceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_requires_authentication(): void
    {
        $this->getJson('/api/dashboard')->assertUnauthorized();
    }

    public function test_login_with_seeded_user_then_dashboard_has_stats_and_sending(): void
    {
        $this->seed(AdminUserSeeder::class);

        // نفس مصادر السيدر — الـ .env المحلي ممكن يغيّر الافتراضيات
        $email = env('ADMIN_EMAIL', 'admin@example.com');
        $password = env('ADMIN_PASSWORD', 'change-me-please');

        $this->withHeader('Referer', 'http://localhost')
            ->postJson('/api/login', [
                'email' => $email,
                'password' => $password,
            ])
            ->assertOk()
            ->assertJsonPath('user.email', $email)
            ->assertJsonStructure(['user' => ['id', 'name', 'email']]);

        $this->assertAuthenticated('web');

        $this->withHeader('Referer', 'http://localhost')
            ->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonStructure([
                'stats' => [
                    'jobs_total', 'contacts_total', 'queued', 'sent_today',
                    'daily_cap', 'sent_total', 'opened_total', 'open_rate',
                ],
                'sending' => [
                    'enabled', 'paused_reason', 'next_send_at', 'in_window',
                    'window' => ['start', 'end', 'days', 'timezone'],
                    'cap_reached', 'smtp_configured', 'apify_configured', 'template_configured',
                ],
                'recent_activity',
            ]);
    }

    public function test_sources_index_lists_seeded_sources(): void
    {
        $this->seed(SourceSeeder::class);

        $response = $this->actingAs(User::factory()->create())
            ->getJson('/api/sources')
            ->assertOk()
            ->assertJsonCount(3, 'data');

        $names = array_column($response->json('data'), 'name');
        $this->assertContains('LinkedIn Jobs', $names);
        $this->assertContains('Indeed', $names);
        $this->assertContains('Wuzzuf', $names);
    }

    public function test_schedule_settings_validation_rejects_min_not_below_max(): void
    {
        $payload = [
            'min_interval' => 30,
            'max_interval' => 30, // لازم يكون أكبر من min
            'window_start' => '09:00',
            'window_end' => '18:00',
            'days' => [0, 1, 2, 3, 4],
            'timezone' => 'Africa/Cairo',
            'daily_cap' => 40,
            'auto_scrape_enabled' => false,
            'auto_scrape_time' => '10:00',
        ];

        $user = User::factory()->create();

        $this->actingAs($user)
            ->putJson('/api/settings/schedule', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['max_interval']);

        $this->actingAs($user)
            ->putJson('/api/settings/schedule', array_merge($payload, ['max_interval' => 45]))
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('schedule.min_interval', 30)
            ->assertJsonPath('schedule.max_interval', 45);
    }

    public function test_template_preview_renders_placeholders_with_sample_context(): void
    {
        app(SettingsRepository::class)->set('my_name', 'Khaled Waleed');

        $this->actingAs(User::factory()->create())
            ->postJson('/api/settings/template-preview', [
                'subject' => 'Application for {job_title} — {my_name}',
                'body' => 'Dear {contact_name}, I would love to join {company}.',
            ])
            ->assertOk()
            ->assertJsonPath('subject', 'Application for Senior Software Engineer — Khaled Waleed')
            ->assertJsonPath('body', 'Dear HR Team, I would love to join شركة تجريبية.');
    }
}
