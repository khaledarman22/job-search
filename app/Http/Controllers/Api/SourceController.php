<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApifyException;
use App\Http\Controllers\Controller;
use App\Http\Requests\SourceRequest;
use App\Models\ApifyRun;
use App\Models\Source;
use App\Services\Apify\ApifyClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Artisan;

class SourceController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => Source::orderBy('id')->get()->map($this->shape(...))->all(),
        ]);
    }

    public function store(SourceRequest $request): JsonResponse
    {
        $source = Source::create($request->validated());

        return response()->json(['source' => $this->shape($source)], 201);
    }

    public function update(SourceRequest $request, Source $source): JsonResponse
    {
        $source->update($request->validated());

        return response()->json(['source' => $this->shape($source)]);
    }

    public function destroy(Source $source): Response
    {
        $source->delete();

        return response()->noContent();
    }

    /** تشغيل فوري — بنفس منطق outreach:scrape بالظبط عن طريق استدعاء الأمر نفسه */
    public function run(Request $request, Source $source): JsonResponse
    {
        $data = $request->validate([
            'keywords' => ['nullable', 'string', 'max:255'],
            'location' => ['nullable', 'string', 'max:255'],
            'max_items' => ['nullable', 'integer', 'min:1'],
        ]);

        $latestBefore = (int) $source->runs()->max('id');

        $options = ['--source' => [(string) $source->id]];
        if (isset($data['keywords'])) {
            $options['--keywords'] = $data['keywords'];
        }
        if (isset($data['location'])) {
            $options['--location'] = $data['location'];
        }
        if (isset($data['max_items'])) {
            $options['--max-items'] = (string) $data['max_items'];
        }

        Artisan::call('outreach:scrape', $options);

        $run = $source->runs()->where('id', '>', $latestBefore)->orderByDesc('id')->first();
        if (! $run) {
            $output = trim(Artisan::output());

            return response()->json(['message' => $output !== '' ? $output : 'فشل تشغيل الأكتور.'], 422);
        }

        return response()->json(['run' => ApifyRunController::shape($run->load('source:id,name'))], 201);
    }

    /** تشغيل تجريبي: 3 عناصر بحد أقصى + معاينة نتيجة الـ field_map */
    public function test(Request $request, Source $source, ApifyClient $client): JsonResponse
    {
        set_time_limit(120);

        $data = $request->validate([
            'keywords' => ['nullable', 'string', 'max:255'],
            'location' => ['nullable', 'string', 'max:255'],
        ]);

        if (! $client->isConfigured()) {
            return response()->json(['message' => 'Apify token غير مضبوط.'], 422);
        }

        $keywords = $data['keywords'] ?? $source->default_keywords ?? '';
        $location = $data['location'] ?? $source->default_location ?? '';
        $input = $this->renderInput($source->input_template ?? [], $keywords, $location, 3);

        try {
            $run = $client->startActor($source->actor_id, $input, ['maxItems' => 3, 'waitForFinish' => 60]);
        } catch (ConnectionException) {
            // مهلة HTTP خلصت والـ run لسه شغال على Apify — المزامنة الدورية هتلقطه
            return response()->json(['raw_items' => [], 'mapped' => [], 'run_status' => 'RUNNING']);
        } catch (ApifyException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $status = (string) ($run['status'] ?? 'UNKNOWN');
        $runId = $run['id'] ?? null;

        // waitForFinish ممكن يرجّع والـ run لسه شغال — نكمل polling في حدود مهلة المسار
        $deadline = time() + 45;
        while ($runId && in_array($status, ApifyRun::ACTIVE_STATUSES, true) && time() < $deadline) {
            sleep(3);
            try {
                $fresh = $client->getRun($runId);
                $status = (string) ($fresh['status'] ?? $status);
                $run = array_merge($run, $fresh);
            } catch (\Throwable) {
                break;
            }
        }

        $rawItems = [];
        $datasetId = $run['defaultDatasetId'] ?? null;
        if ($datasetId) {
            try {
                $rawItems = array_values(array_filter(
                    array_slice($client->getDatasetItems($datasetId, 0, 3), 0, 3),
                    is_array(...),
                ));
            } catch (\Throwable) {
                // الداتاسيت مش جاهز — نرجّع الحالة من غير عناصر
            }
        }

        $fieldMap = $source->field_map ?? [];
        $mapped = array_map(function (array $item) use ($fieldMap): array {
            $row = [];
            foreach ($fieldMap as $key => $path) {
                $row[$key] = $path ? data_get($item, $path) : null;
            }

            return $row;
        }, $rawItems);

        return response()->json([
            'raw_items' => $rawItems,
            'mapped' => $mapped,
            'run_status' => $status,
        ]);
    }

    /** نفس منطق ScrapeCommand::renderInput — استبدال {keywords}/{location}/{limit} recursively */
    private function renderInput(array $template, string $keywords, string $location, int $limit): array
    {
        $render = function (mixed $value) use (&$render, $keywords, $location, $limit): mixed {
            if (is_array($value)) {
                return array_map($render, $value);
            }
            if (is_string($value)) {
                if ($value === '{limit}') {
                    return $limit;
                }

                return str_replace(
                    ['{keywords}', '{location}', '{limit}'],
                    [$keywords, $location, (string) $limit],
                    $value
                );
            }

            return $value;
        };

        return array_map($render, $template);
    }

    private function shape(Source $source): array
    {
        return [
            'id' => $source->id,
            'name' => $source->name,
            'actor_id' => $source->actor_id,
            'input_template' => $source->input_template,
            'field_map' => $source->field_map,
            'default_keywords' => $source->default_keywords,
            'default_location' => $source->default_location,
            'max_items' => $source->max_items,
            'enabled' => $source->enabled,
            'last_run_at' => $source->last_run_at?->copy()->utc()->toIso8601ZuluString(),
            'created_at' => $source->created_at?->copy()->utc()->toIso8601ZuluString(),
        ];
    }
}
