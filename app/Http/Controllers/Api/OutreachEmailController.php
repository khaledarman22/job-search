<?php

namespace App\Http\Controllers\Api;

use App\Enums\ContactStatus;
use App\Enums\OutreachStatus;
use App\Http\Controllers\Controller;
use App\Models\EmailOpen;
use App\Models\OutreachEmail;
use App\Services\OutreachService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class OutreachEmailController extends Controller
{
    private const RELATIONS = ['contact:id,name,email', 'company:id,name', 'jobPost:id,title'];

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(OutreachStatus::class)],
            'opened' => ['nullable', 'boolean'],
            'q' => ['nullable', 'string', 'max:255'],
        ]);

        $status = $filters['status'] ?? null;

        $query = OutreachEmail::with(self::RELATIONS)
            ->when($status, fn ($q, $v) => $q->where('status', $v))
            ->when(
                $request->filled('opened'),
                fn ($q) => $request->boolean('opened') ? $q->whereNotNull('opened_at') : $q->whereNull('opened_at'),
            )
            ->when($filters['q'] ?? null, function ($q, string $term): void {
                $like = '%'.$term.'%';
                $q->where(fn ($w) => $w
                    ->where('to_email', 'like', $like)
                    ->orWhereHas('company', fn ($c) => $c->where('name', 'like', $like)));
            });

        // الطابور بيتعرض بترتيب الإرسال الفعلي
        if ($status === OutreachStatus::Queued->value) {
            $query->orderBy('position')->orderBy('id');
        } else {
            $query->orderByDesc('id');
        }

        // الطابور بيتجاب بصفحة أكبر عشان السحب-والإفلات يشمل كل الصفوف
        $perPage = $status === OutreachStatus::Queued->value ? 100 : 20;

        return response()->json($query->paginate($perPage)->through(fn (OutreachEmail $e) => self::shape($e)));
    }

    public function show(OutreachEmail $email): JsonResponse
    {
        $email->load([...self::RELATIONS, 'opens']);

        $shaped = self::shape($email);
        $shaped['body_html'] = $email->body_html;
        $shaped['opens'] = $email->opens
            ->sortByDesc('opened_at')
            ->values()
            ->map(fn (EmailOpen $open) => [
                'opened_at' => $open->opened_at?->copy()->utc()->toIso8601ZuluString(),
                'ip' => $open->ip,
                'user_agent' => $open->user_agent,
            ])
            ->all();

        return response()->json(['email' => $shaped]);
    }

    public function cancel(OutreachEmail $email): JsonResponse
    {
        if ($email->status !== OutreachStatus::Queued) {
            return response()->json(['message' => 'الإلغاء متاح للرسائل اللي في الطابور فقط.'], 409);
        }

        $email->update(['status' => OutreachStatus::Cancelled]);

        // جهة الاتصال ترجع لحالتها الطبيعية — contacted لو اترسل لها قبل كده
        $contact = $email->contact;
        if ($contact && $contact->status !== ContactStatus::Suppressed) {
            $hasSent = $contact->outreachEmails()->where('status', OutreachStatus::Sent)->exists();
            $contact->update(['status' => $hasSent ? ContactStatus::Contacted : ContactStatus::New]);
        }

        return response()->json(['email' => self::shape($email->load(self::RELATIONS))]);
    }

    public function sendNow(OutreachEmail $email, OutreachService $outreach): JsonResponse
    {
        if ($email->status !== OutreachStatus::Queued) {
            return response()->json(['message' => 'الإرسال الفوري متاح للرسائل اللي في الطابور فقط.'], 409);
        }

        try {
            $updated = $outreach->sendImmediate($email);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'فشل الإرسال: ' . $e->getMessage()], 500);
        }

        return response()->json(['email' => self::shape($updated->load(self::RELATIONS))]);
    }

    public function requeue(OutreachEmail $email, OutreachService $outreach): JsonResponse
    {
        $allowed = [OutreachStatus::Failed, OutreachStatus::Cancelled, OutreachStatus::Sent];
        if (! in_array($email->status, $allowed, true)) {
            return response()->json(['message' => 'إعادة الإدراج متاحة للمرسل أو الفاشل أو الملغي فقط.'], 409);
        }

        $contact = $email->contact;
        if (! $contact) {
            return response()->json(['message' => 'جهة الاتصال اتمسحت.', 'code' => 'no_contact'], 409);
        }

        try {
            $new = $outreach->queueContact($contact, $email->jobPost, manual: true);
        } catch (\DomainException $e) {
            return response()->json([
                'message' => self::queueErrorMessage($e->getMessage()),
                'code' => $e->getMessage(),
            ], 409);
        }

        return response()->json(['email' => self::shape($new->load(self::RELATIONS))], 201);
    }

    public function update(Request $request, OutreachEmail $email): JsonResponse
    {
        $data = $request->validate(['replied' => ['required', 'boolean']]);

        if ($email->status !== OutreachStatus::Sent) {
            return response()->json(['message' => '«تم الرد» متاحة للرسائل المرسلة فقط.'], 409);
        }

        $email->update(['replied_at' => $data['replied'] ? now() : null]);

        return response()->json(['email' => self::shape($email->load(self::RELATIONS))]);
    }

    public function reorder(Request $request): Response
    {
        $data = $request->validate([
            'ordered_ids' => ['required', 'array'],
            'ordered_ids.*' => ['integer'],
        ]);

        foreach (array_values($data['ordered_ids']) as $index => $id) {
            OutreachEmail::whereKey($id)
                ->where('status', OutreachStatus::Queued)
                ->update(['position' => $index + 1]);
        }

        return response()->noContent();
    }

    public function pause(OutreachService $outreach): JsonResponse
    {
        $outreach->pause();

        return response()->json(['sending' => $outreach->sendingState()]);
    }

    public function resume(OutreachService $outreach): JsonResponse
    {
        $outreach->resume();

        return response()->json(['sending' => $outreach->sendingState()]);
    }

    public static function shape(OutreachEmail $email): array
    {
        return [
            'id' => $email->id,
            'to_email' => $email->to_email,
            'subject' => $email->subject,
            'status' => $email->status->value,
            'is_manual' => $email->is_manual,
            'attempts' => $email->attempts,
            'error' => $email->error,
            'position' => $email->position,
            'queued_at' => $email->queued_at?->copy()->utc()->toIso8601ZuluString(),
            'sent_at' => $email->sent_at?->copy()->utc()->toIso8601ZuluString(),
            'opened_at' => $email->opened_at?->copy()->utc()->toIso8601ZuluString(),
            'last_opened_at' => $email->last_opened_at?->copy()->utc()->toIso8601ZuluString(),
            'open_count' => $email->open_count,
            'replied_at' => $email->replied_at?->copy()->utc()->toIso8601ZuluString(),
            'contact' => $email->contact
                ? ['id' => $email->contact->id, 'name' => $email->contact->name, 'email' => $email->contact->email]
                : null,
            'company' => $email->company
                ? ['id' => $email->company->id, 'name' => $email->company->name]
                : null,
            'job_post' => $email->jobPost
                ? ['id' => $email->jobPost->id, 'title' => $email->jobPost->title]
                : null,
        ];
    }

    /** رسائل عربية موحّدة لأكواد أخطاء الإدراج (409) */
    public static function queueErrorMessage(string $code): string
    {
        return match ($code) {
            'suppressed' => 'جهة الاتصال في قائمة الاستبعاد.',
            'no_email' => 'جهة الاتصال ملهاش بريد إلكتروني.',
            'no_contact' => 'لا توجد جهة اتصال بإيميل لهذه الشركة.',
            'already_queued' => 'جهة الاتصال موجودة في الطابور بالفعل.',
            'already_sent' => 'تم الإرسال لجهة الاتصال دي قبل كده — تقدر تعيد الإدراج من صفحة جهات الاتصال.',
            default => 'تعذر إدراج جهة الاتصال في الطابور.',
        };
    }
}
