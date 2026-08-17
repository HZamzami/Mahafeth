<?php

namespace App\Http\Requests\Api\V1;

use App\Models\AlertRule;
use Illuminate\Foundation\Http\FormRequest;

class AlertRuleRequest extends FormRequest
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
            'metric' => ['required', 'string', 'in:'.implode(',', array_keys(AlertRule::METRICS))],
            'threshold' => ['required', 'numeric', 'min:1', 'max:100'],
        ];
    }
}
