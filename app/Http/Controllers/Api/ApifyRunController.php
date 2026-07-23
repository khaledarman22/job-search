<?php

namespace App\Http\Controllers\Api;

use App\Enums\RunOrigin;
use App\Enums\RunPurpose;
use App\Http\Controllers\Controller;
use App\Models\ApifyRun;
use App\Services\Apify\ApifyClient;
use App\Services\Apify\RunImporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Validation\Rule;

class ApifyRunController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'purpose' => ['nullable', Rule::enum(RunPurpose::class)],
            'origin' => ['nullable', Rule::enum(RunOrigin::class)],
            'status' => ['nullable', 'string', 'max:30'],
        ]);

        $paginator = ApifyRun::with('source:id,name')
            ->when($filters['purpose'] ?? null, fn ($q, $v) => $q->where('purpose', $v))
            ->when($filters['origin'] ?? null, fn ($q, $v) => $q->where('origin', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->paginate(20)
            ->through(fn (ApifyRun $run) => self::shape($run));

        return response()->json($paginator);
    }

    public function show(ApifyRun $run, ApifyClient $client): JsonResponse
    {
        $run->load('source:id,name');

        // lazy-fill: نجيب الـ input وعدد العناصر من Apify لو ناقصين
        if ($client->isConfigured()) {
            if ($run->input === null && $run->default_kv_store_id) {
                try {
                    $input = $client->getRunInput($run->default_kv_store_id);
                    if ($input !== null) {
                        $run->input = $input;
                    }
                } catch (\Throwable) {
                    // فشل الجلب ميمنعش عرض الـ run
                }
            }

            if ($run->item_count === null && $run->default_dataset_id) {
                try {
                    $dataset = $client->getDataset($run->default_dataset_id);
                    if (isset($dataset['itemCount'])) {
                        $run->item_count = (int) $dataset['itemCount'];
                    }
                } catch (\Throwable) {
                    // نفس الفكرة
                }
            }

            if ($run->isDirty()) {
                $run->save();
            }
        }

        return response()->json(['run' => self::shape($run)]);
    }

    /** استيراد يدوي متزامن لداتاسِت الـ run باستخدام field_map بتاع الـ source المختار */
    public function import(Request $request, ApifyRun $run, RunImporter $importer): JsonResponse
    {
        $data = $request->validate([
            'source_id' => ['required', 'integer', 'exists:sources,id'],
        ]);

        set_time_limit(300);

        $run->source_id = (int) $data['source_id'];
        $run->save();
        $run->unsetRelation('source');

        try {
            $imported = $importer->import($run);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['imported' => $imported]);
    }

    public function sync(): JsonResponse
    {
        set_time_limit(300);

        $before = ApifyRun::count();

        Artisan::call('apify:sync-history');

        $synced = null;
        if (preg_match('/Synced (\d+) new run/', Artisan::output(), $matches)) {
            $synced = (int) $matches[1];
        }

        return response()->json(['synced' => $synced ?? ApifyRun::count() - $before]);
    }

    public static function shape(ApifyRun $run): array
    {
        return [
            'id' => $run->id,
            'apify_run_id' => $run->apify_run_id,
            'actor_id' => $run->actor_id,
            'actor_name' => $run->actor_name,
            'source_id' => $run->source_id,
            'source_name' => $run->source?->name,
            'purpose' => $run->purpose->value,
            'origin' => $run->origin->value,
            'status' => $run->status,
            'input' => $run->input,
            'item_count' => $run->item_count,
            'usage_usd' => $run->usage_usd !== null ? (float) $run->usage_usd : null,
            'started_at' => $run->started_at?->copy()->utc()->toIso8601ZuluString(),
            'finished_at' => $run->finished_at?->copy()->utc()->toIso8601ZuluString(),
            'imported_at' => $run->imported_at?->copy()->utc()->toIso8601ZuluString(),
            'error' => $run->error,
        ];
    }
}
