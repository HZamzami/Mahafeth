<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class ZakatRequest extends FormRequest
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
            'hawl_month' => ['required', 'integer', 'between:1,12'],
            'hawl_day' => ['required', 'integer', 'between:1,30'],
        ];
    }
}
