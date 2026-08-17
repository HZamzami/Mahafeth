<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class WhatIfRequest extends FormRequest
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
            'amount' => ['required', 'numeric', 'min:0.01'],
            'side' => ['required', 'string', 'in:buy,sell'],
        ];
    }

    public function isSell(): bool
    {
        return $this->validated('side') === 'sell';
    }
}
