<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ScheduleSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'min_interval' => ['required', 'integer', 'between:1,720'],
            'max_interval' => ['required', 'integer', 'between:1,720', 'gt:min_interval'],
            'window_start' => ['required', 'date_format:H:i'],
            'window_end' => ['required', 'date_format:H:i'],
            'days' => ['required', 'array', 'min:1'],
            'days.*' => ['integer', 'between:0,6'],
            'timezone' => [
                'required', 'string',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! in_array($value, timezone_identifiers_list(), true)) {
                        $fail('المنطقة الزمنية غير صالحة.');
                    }
                },
            ],
            'daily_cap' => ['required', 'integer', 'between:1,100'],
            'auto_scrape_enabled' => ['required', 'boolean'],
            'auto_scrape_time' => ['required', 'date_format:H:i'],
        ];
    }
}
