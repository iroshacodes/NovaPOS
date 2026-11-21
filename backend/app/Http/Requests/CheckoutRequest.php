<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CheckoutRequest extends FormRequest
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
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.product_id' => [
                'required',
                'integer',
                Rule::exists('products', 'id')->whereNull('deleted_at'),
            ],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:10000'],
            'discount_percent' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'payment_method' => ['required', 'string', Rule::in(['cash', 'card'])],
        ];
    }
}
