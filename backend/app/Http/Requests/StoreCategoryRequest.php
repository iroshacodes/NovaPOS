<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100', Rule::unique('categories', 'name')],
            'slug' => ['required', 'string', 'max:120', 'alpha_dash', Rule::unique('categories', 'slug')],
            'description' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
