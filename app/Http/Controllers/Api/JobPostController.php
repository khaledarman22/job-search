<?php

namespace App\Http\Controllers\Api;

use App\Enums\ContactStatus;
use App\Enums\EnrichmentStatus;
use App\Enums\JobPostStatus;
use App\Enums\OutreachStatus;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Contact;
use App\Models\JobPost;
use App\Models\OutreachEmail;
use App\Services\OutreachService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class JobPostController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(JobPostStatus::class)],
            'source_id' => ['nullable', 'integer'],
            'has_contact' => ['nullable', 'boolean'],
            'q' => ['nullable', 'string', 'max:255'],
        ]);

        $emailContacts = fn ($c) => $c->whereNotNull('email');

        $paginator = JobPost::with(['source:id,name', 'company' => fn ($q) => $q->withCount('contacts')])
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['source_id'] ?? null, fn ($q, $v) => $q->where('source_id', $v))
            ->when(
                $request->filled('has_contact'),
                fn ($q) => $request->boolean('has_contact')
                    ? $q->whereHas('company.contacts', $emailContacts)
                    : $q->whereDoesntHave('company.contacts', $emailContacts),
            )
            ->when($filters['q'] ?? null, function ($q, string $term): void {
                $like = '%'.$term.'%';
                $q->where(fn ($w) => $w
                    ->where('title', 'like', $like)
                    ->orWhereHas('company', fn ($c) => $c->where('name', 'like', $like)));
            })
            ->orderByDesc('id')
            ->paginate(20)
            ->through(fn (JobPost $job) => $this->shape($job));

        return response()->json($paginator);
    }

    public function show(JobPost $jobPost): JsonResponse
    {
        $jobPost->load(['source:id,name', 'company' => fn ($q) => $q->withCount('contacts')]);

        $shaped = $this->shape($jobPost);

        if ($jobPost->company) {
            $shaped['company']['contacts'] = ContactController::listQuery()
                ->where('company_id', $jobPost->company_id)
                ->orderBy('is_alternate')
                ->orderBy('contacts.id')
                ->get()
                ->map(fn (Contact $contact) => ContactController::shape($contact))
                ->all();
        }

        return response()->json(['job' => $shaped]);
    }

    /** إدراج أفضل جهة اتصال للشركة في الطابور بسياق الوظيفة دي */
    public function queue(JobPost $jobPost, OutreachService $outreach): JsonResponse
    {
        $company = $jobPost->company;
        if (! $company) {
            return $this->conflict('no_contact');
        }

        $candidates = $company->contacts()
            ->whereNotNull('email')
            ->orderBy('is_alternate')
            ->orderBy('id')
            ->get();

        if ($candidates->isEmpty()) {
            return $this->conflict('no_contact');
        }

        $contact = $candidates->first(fn (Contact $c) => $c->status !== ContactStatus::Suppressed);
        if (! $contact) {
            return $this->conflict('suppressed');
        }

        // من صفحة الوظائف مفيش إعادة إرسال ضمنية — دي بتتم صراحةً من صفحة جهات الاتصال
        $alreadySent = OutreachEmail::where('to_email', $contact->email)
            ->where('status', OutreachStatus::Sent)
            ->exists();
        if ($alreadySent) {
            return $this->conflict('already_sent');
        }

        try {
            $email = $outreach->queueContact($contact, $jobPost, manual: true);
        } catch (\DomainException $e) {
            return $this->conflict($e->getMessage());
        }

        $email->load(['contact:id,name,email', 'company:id,name', 'jobPost:id,title']);

        return response()->json(['email' => OutreachEmailController::shape($email)], 201);
    }

    /** إعادة ضبط حالة الإثراء — دورة outreach:enrich الجاية هتلقط الشركة */
    public function enrichCompany(Company $company): JsonResponse
    {
        $company->update([
            'enrichment_status' => EnrichmentStatus::Pending,
            'enriched_at' => null,
        ]);

        return response()->json(['company' => $this->companyShape($company)], 202);
    }

    private function conflict(string $code): JsonResponse
    {
        return response()->json([
            'message' => OutreachEmailController::queueErrorMessage($code),
            'code' => $code,
        ], 409);
    }

    private function shape(JobPost $job): array
    {
        return [
            'id' => $job->id,
            'title' => $job->title,
            'url' => $job->url,
            'location' => $job->location,
            'salary' => $job->salary,
            'description' => $job->description,
            'posted_at' => $job->posted_at?->copy()->utc()->toIso8601ZuluString(),
            'status' => $job->status->value,
            'source' => $job->source
                ? ['id' => $job->source->id, 'name' => $job->source->name]
                : null,
            'company' => $job->company ? $this->companyShape($job->company) : null,
            'created_at' => $job->created_at?->copy()->utc()->toIso8601ZuluString(),
        ];
    }

    private function companyShape(Company $company): array
    {
        return [
            'id' => $company->id,
            'name' => $company->name,
            'domain' => $company->domain,
            'website' => $company->website,
            'enrichment_status' => $company->enrichment_status->value,
            'contacts_count' => (int) ($company->getAttribute('contacts_count') ?? $company->contacts()->count()),
        ];
    }
}
