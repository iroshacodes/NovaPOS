<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('sku')) {
            $this->merge([
                'sku' => strtoupper(trim((string) $this->input('sku'))),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'category_id' => ['required', 'integer', Rule::exists('categories', 'id')],
            'name' => ['required', 'string', 'max:150'],
            'sku' => [
                'required',
                'string',
                'max:64',
                Rule::unique('products', 'sku'),
            ],
            'price' => ['required', 'numeric', 'min:0', 'max:99999.99'],
            'cost_price' => ['sometimes', 'numeric', 'min:0', 'max:99999.99'],
            'stock_quantity' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
            'low_stock_threshold' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
            'image_url' => ['nullable', 'string', 'max:500', 'url:https'],
        ];
    }
}
