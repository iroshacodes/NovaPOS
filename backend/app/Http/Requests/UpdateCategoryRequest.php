<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCategoryRequest extends FormRequest
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
        $categoryId = $this->route('category')?->id ?? $this->route('category');

        return [
            'name' => ['sometimes', 'required', 'string', 'max:100', Rule::unique('categories', 'name')->ignore($categoryId)],
            'slug' => ['sometimes', 'required', 'string', 'max:120', 'alpha_dash', Rule::unique('categories', 'slug')->ignore($categoryId)],
            'description' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
