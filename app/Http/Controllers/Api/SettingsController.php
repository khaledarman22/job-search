<?php

namespace App\Http\Controllers\Api;

use App\Console\Commands\ScrapeCommand;
use App\Http\Controllers\Controller;
use App\Http\Requests\ScheduleSettingsRequest;
use App\Http\Requests\SmtpSettingsRequest;
use App\Services\Apify\ApifyClient;
use App\Services\Apify\RunImporter;
use App\Services\ApifySpendTracker;
use App\Services\MailerService;
use App\Services\TemplateRenderer;
use App\Support\SettingsRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SettingsController extends Controller
{
    private const PROFILE_KEYS = ['my_name', 'my_title', 'whatsapp', 'phone', 'portfolio', 'github', 'linkedin'];

    public function __construct(private SettingsRepository $settings) {}

    public function show(): JsonResponse
    {
        return response()->json([
            'apify' => $this->apifyShape(),
            'smtp' => $this->smtpShape(),
            'template' => $this->templateShape(),
            'schedule' => $this->scheduleShape(),
            'auto_scrape' => $this->autoScrapeShape(),
            'profile' => $this->profileShape(),
            'cv' => $this->cvShape(),
        ]);
    }

    public function updateApify(Request $request, ApifyClient $client): JsonResponse
    {
        $data = $request->validate([
            'token' => ['nullable', 'string', 'max:255'],
            'enrich_actor_id' => ['nullable', 'string', 'max:255'],
            'spend_cap' => ['nullable', 'numeric', 'min:0', 'max:1000'],
        ]);

        if (array_key_exists('spend_cap', $data) && $data['spend_cap'] !== null) {
            $this->settings->set('apify_spend_cap', (string) $data['spend_cap']);
        }

        $accountName = null;

        if (! blank($data['token'] ?? null)) {
            $hadPrevious = $this->settings->has('apify_token');
            $previous = $this->settings->get('apify_token');

            // بنحفظ الأول عشان الـ client (بـ repository خاص به) يقرا التوكن الجديد من القاعدة
            $this->settings->set('apify_token', $data['token']);

            try {
                $me = $client->me();
                $accountName = $me['username'] ?? $me['email'] ?? null;
            } catch (\Throwable $e) {
                // توكن بايظ — نرجّع القديم زي ما كان
                if ($hadPrevious) {
                    $this->settings->set('apify_token', $previous);
                } else {
                    $this->settings->forget('apify_token');
                }

                throw ValidationException::withMessages([
                    'token' => ['توكن Apify غير صالح: '.$e->getMessage()],
                ]);
            }

            // تغيير الحساب → تصفير عدّاد الإنفاق واستئناف الاسكراب التلقائي
            app(ApifySpendTracker::class)->reset();

            // مزامنة تاريخ الحساب فورًا — فشلها ميفشلش حفظ التوكن
            try {
                set_time_limit(300);
                Artisan::call('apify:sync-history');
            } catch (\Throwable) {
            }
        }

        if (! blank($data['enrich_actor_id'] ?? null)) {
            $this->settings->set('enrich_actor_id', $data['enrich_actor_id']);
        }

        return response()->json([
            'ok' => true,
            'account_name' => $accountName,
            'apify' => $this->apifyShape(),
        ]);
    }

    public function updateSmtp(SmtpSettingsRequest $request): JsonResponse
    {
        $data = $request->validated();

        $this->settings->set('smtp_host', $data['host']);
        $this->settings->set('smtp_port', $data['port']);
        $this->settings->set('smtp_encryption', $data['encryption']);
        $this->settings->set('smtp_username', $data['username'] ?? '');
        $this->settings->set('from_email', $data['from_email']);
        $this->settings->set('from_name', $data['from_name'] ?? '');

        // باسورد فاضية = احتفظ بالقديمة
        if (! blank($data['password'] ?? null)) {
            $this->settings->set('smtp_password', $data['password']);
        }

        return response()->json(['ok' => true, 'smtp' => $this->smtpShape()]);
    }

    public function smtpTest(Request $request, MailerService $mailer): JsonResponse
    {
        $data = $request->validate(['to' => ['required', 'email']]);

        try {
            $mailer->sendTest($data['to']);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true]);
    }

    public function updateTemplate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'subject' => ['required', 'string', 'max:998'],
            'body' => ['required', 'string'],
        ]);

        $this->settings->set('email_subject_template', $data['subject']);
        $this->settings->set('email_body_template', $data['body']);

        return response()->json(['ok' => true, 'template' => $this->templateShape()]);
    }

    public function templatePreview(Request $request, TemplateRenderer $renderer): JsonResponse
    {
        $data = $request->validate([
            'subject' => ['required', 'string'],
            'body' => ['required', 'string'],
        ]);

        $context = $renderer->sampleContext();

        return response()->json([
            'subject' => $renderer->render($data['subject'], $context),
            'body' => $renderer->render($data['body'], $context),
        ]);
    }

    public function updateSchedule(ScheduleSettingsRequest $request): JsonResponse
    {
        $data = $request->validated();

        $this->settings->set('send_min_interval_minutes', $data['min_interval']);
        $this->settings->set('send_max_interval_minutes', $data['max_interval']);
        $this->settings->set('send_window_start', $data['window_start']);
        $this->settings->set('send_window_end', $data['window_end']);
        $this->settings->set('send_days', array_values(array_map(intval(...), $data['days'])));
        $this->settings->set('send_timezone', $data['timezone']);
        $this->settings->set('daily_send_cap', $data['daily_cap']);
        $this->settings->set('auto_scrape_enabled', (bool) $data['auto_scrape_enabled']);
        $this->settings->set('auto_scrape_time', $data['auto_scrape_time']);

        return response()->json(['ok' => true, 'schedule' => $this->scheduleShape()]);
    }

    public function updateAutoScrape(Request $request): JsonResponse
    {
        $codes = array_column(ScrapeCommand::ARAB_LOCATIONS, 'country');

        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'time' => ['required', 'date_format:H:i'],
            'keywords' => ['nullable', 'string', 'max:255'],
            'location' => [
                'nullable', 'string',
                function (string $attribute, mixed $value, \Closure $fail) use ($codes): void {
                    if ($value !== null && $value !== '' && ! in_array($value, $codes, true)) {
                        $fail('كود الدولة غير صالح.');
                    }
                },
            ],
            'random_sources' => ['required', 'boolean'],
            'auto_queue' => ['required', 'boolean'],
            // غيابه = سيبه زي ما هو؛ وجوده لازم يكون من الثلاثة
            'import_mode' => ['sometimes', 'required', Rule::in(RunImporter::MODES)],
        ]);

        $this->settings->set('auto_scrape_enabled', (bool) $data['enabled']);
        $this->settings->set('auto_scrape_time', $data['time']);
        $this->settings->set('auto_scrape_keywords', $data['keywords'] ?? '');
        $this->settings->set('auto_scrape_location', $data['location'] ?? '');
        $this->settings->set('scrape_random_sources', (bool) $data['random_sources']);
        $this->settings->set('auto_queue_enabled', (bool) $data['auto_queue']);

        if (array_key_exists('import_mode', $data)) {
            $this->settings->set('import_mode', $data['import_mode']);
        }

        return response()->json(['ok' => true, 'auto_scrape' => $this->autoScrapeShape()]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $data = $request->validate(
            array_fill_keys(self::PROFILE_KEYS, ['nullable', 'string', 'max:255']),
        );

        foreach (self::PROFILE_KEYS as $key) {
            if (array_key_exists($key, $data)) {
                $this->settings->set($key, $data[$key] ?? '');
            }
        }

        return response()->json(['ok' => true, 'profile' => $this->profileShape()]);
    }

    public function uploadCv(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:pdf', 'max:5120'],
        ]);

        $file = $request->file('file');
        Storage::disk('local')->putFileAs('private', $file, 'cv.pdf');

        $this->settings->set('cv_path', 'private/cv.pdf');
        $this->settings->set('cv_original_name', $file->getClientOriginalName());

        return response()->json(['cv' => $this->cvShape()]);
    }

    public function downloadCv(): StreamedResponse|JsonResponse
    {
        $path = $this->settings->get('cv_path');
        if (! $path || ! Storage::disk('local')->exists($path)) {
            return response()->json(['message' => 'لا يوجد ملف CV مرفوع.'], 404);
        }

        return Storage::disk('local')->download($path, $this->settings->get('cv_original_name') ?? 'cv.pdf');
    }

    private function apifyShape(): array
    {
        $token = $this->settings->has('apify_token') ? (string) $this->settings->get('apify_token') : null;

        return [
            'token_set' => $token !== null,
            'token_masked' => $token !== null
                ? substr($token, 0, 10).'•••'.substr($token, -4)
                : null,
            'enrich_actor_id' => $this->settings->get('enrich_actor_id'),
            'spend_cap' => (float) $this->settings->get('apify_spend_cap', '4'),
            'spend' => app(ApifySpendTracker::class)->state(),
        ];
    }

    private function smtpShape(): array
    {
        return [
            'host' => $this->settings->get('smtp_host'),
            'port' => $this->settings->getInt('smtp_port', 587),
            'encryption' => $this->settings->get('smtp_encryption'),
            'username' => $this->settings->get('smtp_username') ?? '',
            'password_set' => $this->settings->has('smtp_password'),
            'from_email' => $this->settings->get('from_email') ?? '',
            'from_name' => $this->settings->get('from_name') ?? '',
        ];
    }

    private function templateShape(): array
    {
        return [
            'subject' => $this->settings->get('email_subject_template') ?? '',
            'body' => $this->settings->get('email_body_template') ?? '',
        ];
    }

    private function scheduleShape(): array
    {
        return [
            'min_interval' => $this->settings->getInt('send_min_interval_minutes', 15),
            'max_interval' => $this->settings->getInt('send_max_interval_minutes', 45),
            'window_start' => $this->settings->get('send_window_start'),
            'window_end' => $this->settings->get('send_window_end'),
            'days' => array_map(intval(...), $this->settings->getArray('send_days', [0, 1, 2, 3, 4])),
            'timezone' => $this->settings->get('send_timezone'),
            'daily_cap' => $this->settings->getInt('daily_send_cap', 40),
            'auto_scrape_enabled' => $this->settings->getBool('auto_scrape_enabled'),
            'auto_scrape_time' => $this->settings->get('auto_scrape_time'),
        ];
    }

    private function autoScrapeShape(): array
    {
        return [
            'enabled' => $this->settings->getBool('auto_scrape_enabled'),
            'time' => $this->settings->get('auto_scrape_time'),
            'keywords' => $this->settings->get('auto_scrape_keywords') ?? '',
            'location' => $this->settings->get('auto_scrape_location') ?? '',
            'random_sources' => $this->settings->getBool('scrape_random_sources', true),
            'auto_queue' => $this->settings->getBool('auto_queue_enabled', true),
            'import_mode' => (string) $this->settings->get('import_mode', RunImporter::MODE_ALL),
            'available_locations' => array_map(
                fn (array $l): array => ['code' => $l['country'], 'name' => $l['location']],
                ScrapeCommand::ARAB_LOCATIONS,
            ),
        ];
    }

    private function profileShape(): array
    {
        $profile = [];
        foreach (self::PROFILE_KEYS as $key) {
            $profile[$key] = $this->settings->get($key) ?? '';
        }

        return $profile;
    }

    private function cvShape(): array
    {
        $path = $this->settings->get('cv_path');
        $exists = $path && Storage::disk('local')->exists($path);

        return [
            'uploaded' => (bool) $exists,
            'original_name' => $exists ? $this->settings->get('cv_original_name') : null,
            'size' => $exists ? Storage::disk('local')->size($path) : null,
        ];
    }
}
