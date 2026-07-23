<?php

namespace App\Http\Controllers\Api;

use App\Enums\OutreachStatus;
use App\Http\Controllers\Controller;
use App\Models\ApifyRun;
use App\Models\Contact;
use App\Models\JobPost;
use App\Models\OutreachEmail;
use App\Services\ApifySpendTracker;
use App\Services\OutreachService;
use App\Support\SettingsRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

class DashboardController extends Controller
{
    public function __construct(
        private OutreachService $outreach,
        private SettingsRepository $settings,
        private ApifySpendTracker $spend,
    ) {}

    public function __invoke(): JsonResponse
    {
        $sentTotal = OutreachEmail::where('status', OutreachStatus::Sent)->count();
        $openedTotal = OutreachEmail::where('status', OutreachStatus::Sent)->whereNotNull('opened_at')->count();

        // بداية اليوم بتوقيت الإرسال — مش UTC
        $timezone = $this->settings->get('send_timezone') ?? 'Africa/Cairo';
        $todayStartUtc = Carbon::now($timezone)->startOfDay()->utc();

        return response()->json([
            'stats' => [
                'jobs_total' => JobPost::count(),
                'contacts_total' => Contact::count(),
                'queued' => OutreachEmail::where('status', OutreachStatus::Queued)->count(),
                'sent_today' => OutreachEmail::where('status', OutreachStatus::Sent)
                    ->where('sent_at', '>=', $todayStartUtc)
                    ->count(),
                'daily_cap' => $this->settings->getInt('daily_send_cap', 40),
                'sent_total' => $sentTotal,
                'opened_total' => $openedTotal,
                'open_rate' => $sentTotal > 0 ? round($openedTotal / $sentTotal, 3) : 0.0,
            ],
            'sending' => $this->outreach->sendingState(),
            'apify_spend' => $this->spend->state(),
            'recent_activity' => $this->recentActivity(),
        ]);
    }

    /** آخر 20 حدث — دمج من الإرسال/الفتح/الفشل + انتهاء الـ runs + جهات الاتصال الجديدة */
    private function recentActivity(): array
    {
        $events = collect();

        $withCompany = fn (OutreachEmail $e): string => $e->company ? ' — شركة '.$e->company->name : '';

        OutreachEmail::with('company:id,name')
            ->where('status', OutreachStatus::Sent)
            ->whereNotNull('sent_at')
            ->orderByDesc('sent_at')->limit(20)->get()
            ->each(function (OutreachEmail $e) use ($events, $withCompany): void {
                $events->push([
                    'type' => 'sent',
                    'message' => 'تم إرسال بريد إلى '.$e->to_email.$withCompany($e),
                    'at' => $e->sent_at,
                ]);
            });

        OutreachEmail::with('company:id,name')
            ->where('status', OutreachStatus::Failed)
            ->whereNotNull('failed_at')
            ->orderByDesc('failed_at')->limit(20)->get()
            ->each(function (OutreachEmail $e) use ($events, $withCompany): void {
                $events->push([
                    'type' => 'failed',
                    'message' => 'فشل إرسال بريد إلى '.$e->to_email.$withCompany($e),
                    'at' => $e->failed_at,
                ]);
            });

        OutreachEmail::with('company:id,name')
            ->whereNotNull('last_opened_at')
            ->orderByDesc('last_opened_at')->limit(20)->get()
            ->each(function (OutreachEmail $e) use ($events, $withCompany): void {
                $events->push([
                    'type' => 'opened',
                    'message' => 'تم فتح البريد المرسل إلى '.$e->to_email.$withCompany($e),
                    'at' => $e->last_opened_at,
                ]);
            });

        ApifyRun::whereNotNull('finished_at')
            ->orderByDesc('finished_at')->limit(20)->get()
            ->each(function (ApifyRun $run) use ($events): void {
                $name = $run->actor_name ?: $run->actor_id;
                $items = $run->item_count !== null ? " ({$run->item_count} عنصر)" : '';
                $events->push([
                    'type' => 'run_finished',
                    'message' => "انتهت عملية {$name} بحالة {$run->status}{$items}",
                    'at' => $run->finished_at,
                ]);
            });

        Contact::with('company:id,name')
            ->orderByDesc('created_at')->limit(20)->get()
            ->each(function (Contact $contact) use ($events): void {
                $label = $contact->email ?? $contact->phone ?? '—';
                $company = $contact->company ? ' — شركة '.$contact->company->name : '';
                $events->push([
                    'type' => 'contact_found',
                    'message' => 'تم العثور على جهة اتصال: '.$label.$company,
                    'at' => $contact->created_at,
                ]);
            });

        return $events
            ->sortByDesc('at')
            ->take(20)
            ->values()
            ->map(fn (array $event): array => [
                'type' => $event['type'],
                'message' => $event['message'],
                'at' => $event['at']?->copy()->utc()->toIso8601ZuluString(),
            ])
            ->all();
    }
}
