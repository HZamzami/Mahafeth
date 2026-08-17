<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class RecordTransactionRequest extends FormRequest
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
        $isTrade = in_array($this->input('type'), ['buy', 'sell'], true);

        return [
            'type' => ['required', 'string', 'in:buy,sell,dividend,deposit,withdrawal'],
            'executed_at' => ['nullable', 'date'],

            'symbol' => [$isTrade ? 'required' : 'exclude', 'string'],
            'meta' => ['nullable', 'array'],
            'quantity' => [$isTrade ? 'required' : 'exclude', 'numeric', 'min:0.00000001'],
            'price' => [$isTrade ? 'required' : 'exclude', 'numeric', 'min:0'],

            'currency' => [$isTrade ? 'exclude' : 'required', 'string', 'in:SAR,USD'],
            'amount' => [$isTrade ? 'exclude' : 'required', 'numeric', 'min:0.01'],
        ];
    }
}
