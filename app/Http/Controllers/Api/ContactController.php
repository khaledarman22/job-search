<?php

namespace App\Http\Controllers\Api;

use App\Enums\ContactStatus;
use App\Enums\OutreachStatus;
use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\JobPost;
use App\Models\OutreachEmail;
use App\Services\OutreachService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class ContactController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(ContactStatus::class)],
            'phone_only' => ['nullable', 'boolean'],
            'sent_before' => ['nullable', 'boolean'],
            'q' => ['nullable', 'string', 'max:255'],
        ]);

        $paginator = self::listQuery()
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when(
                $request->boolean('phone_only'),
                fn ($q) => $q->whereNull('email')->whereNotNull('phone'),
            )
            ->when(
                $request->boolean('sent_before'),
                fn ($q) => $q->whereHas('outreachEmails', fn ($o) => $o->where('status', OutreachStatus::Sent)),
            )
            ->when($filters['q'] ?? null, function ($q, string $term): void {
                $like = '%'.$term.'%';
                $q->where(fn ($w) => $w
                    ->where('email', 'like', $like)
                    ->orWhere('name', 'like', $like)
                    ->orWhereHas('company', fn ($c) => $c->where('name', 'like', $like)));
            })
            ->orderByDesc('contacts.id')
            ->paginate(20)
            ->through(fn (Contact $contact) => self::shape($contact));

        return response()->json($paginator);
    }

    public function update(Request $request, Contact $contact): JsonResponse
    {
        $data = $request->validate([
            'email' => [
                'sometimes', 'nullable', 'email', 'max:255',
                Rule::unique('contacts', 'email')->ignore($contact->id),
            ],
            'phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'position' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $contact->update($data);

        return response()->json(['contact' => self::shape(self::fresh($contact))]);
    }

    public function queue(Request $request, Contact $contact, OutreachService $outreach): JsonResponse
    {
        $data = $request->validate([
            'job_post_id' => ['nullable', 'integer', 'exists:job_posts,id'],
        ]);

        $job = isset($data['job_post_id']) ? JobPost::find($data['job_post_id']) : null;

        try {
            $email = $outreach->queueContact($contact, $job, manual: true);
        } catch (\DomainException $e) {
            return response()->json([
                'message' => OutreachEmailController::queueErrorMessage($e->getMessage()),
                'code' => $e->getMessage(),
            ], 409);
        }

        $email->load(['contact:id,name,email', 'company:id,name', 'jobPost:id,title']);

        return response()->json(['email' => OutreachEmailController::shape($email)], 201);
    }

    public function suppress(Contact $contact): JsonResponse
    {
        $contact->update(['status' => ContactStatus::Suppressed]);

        return response()->json(['contact' => self::shape(self::fresh($contact))]);
    }

    public function unsuppress(Contact $contact): JsonResponse
    {
        $hasSent = $contact->outreachEmails()->where('status', OutreachStatus::Sent)->exists();
        $contact->update(['status' => $hasSent ? ContactStatus::Contacted : ContactStatus::New]);

        return response()->json(['contact' => self::shape(self::fresh($contact))]);
    }

    /** استعلام القائمة الموحّد: عدّاد المشاهدات + آخر إرسال/فتح من سجل الـ outreach */
    public static function listQuery(): Builder
    {
        return Contact::query()
            ->select('contacts.*')
            ->with(['company:id,name', 'jobPost:id,title'])
            ->withCount('sightings')
            ->addSelect([
                'last_sent_at' => OutreachEmail::select('sent_at')
                    ->whereColumn('contact_id', 'contacts.id')
                    ->whereNotNull('sent_at')
                    ->orderByDesc('sent_at')
                    ->limit(1),
                'last_opened_at' => OutreachEmail::select('last_opened_at')
                    ->whereColumn('contact_id', 'contacts.id')
                    ->whereNotNull('last_opened_at')
                    ->orderByDesc('last_opened_at')
                    ->limit(1),
            ]);
    }

    public static function shape(Contact $contact): array
    {
        return [
            'id' => $contact->id,
            'email' => $contact->email,
            'phone' => $contact->phone,
            'whatsapp_url' => $contact->whatsappUrl(),
            'name' => $contact->name,
            'position' => $contact->position,
            'discovered_via' => $contact->discovered_via->value,
            'is_alternate' => $contact->is_alternate,
            'status' => $contact->status->value,
            'company' => $contact->company
                ? ['id' => $contact->company->id, 'name' => $contact->company->name]
                : null,
            'job_post' => $contact->jobPost
                ? ['id' => $contact->jobPost->id, 'title' => $contact->jobPost->title]
                : null,
            'sightings_count' => (int) ($contact->getAttribute('sightings_count') ?? $contact->sightings()->count()),
            'last_sent_at' => self::isoDate($contact->getAttribute('last_sent_at')),
            'last_opened_at' => self::isoDate($contact->getAttribute('last_opened_at')),
            'created_at' => $contact->created_at?->copy()->utc()->toIso8601ZuluString(),
        ];
    }

    /** إعادة تحميل بنفس أعمدة القائمة عشان الشكل يفضل موحّد */
    private static function fresh(Contact $contact): Contact
    {
        return self::listQuery()->findOrFail($contact->id);
    }

    /** قيم الـ subselect بترجع نص خام مش Carbon */
    private static function isoDate(mixed $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        try {
            return Carbon::parse($value)->utc()->toIso8601ZuluString();
        } catch (\Throwable) {
            return null;
        }
    }
}
