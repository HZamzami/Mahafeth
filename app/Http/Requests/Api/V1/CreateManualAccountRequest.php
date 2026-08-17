<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class CreateManualAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:60'],
            'type' => ['required', 'string', 'in:brokerage,retirement,crypto,fund,savings,cash'],
            'currency' => ['required', 'string', 'in:SAR,USD'],
        ];
    }
}
