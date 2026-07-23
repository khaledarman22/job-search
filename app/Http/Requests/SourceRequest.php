<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SourceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'actor_id' => ['required', 'string', 'max:255'],
            'input_template' => ['required', 'array'],
            'field_map' => ['required', 'array'],
            'default_keywords' => ['nullable', 'string', 'max:255'],
            'default_location' => ['nullable', 'string', 'max:255'],
            'max_items' => ['nullable', 'integer', 'min:1'],
            'enabled' => ['required', 'boolean'],
        ];
    }
}
