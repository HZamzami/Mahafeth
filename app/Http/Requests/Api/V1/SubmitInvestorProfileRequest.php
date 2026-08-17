<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class SubmitInvestorProfileRequest extends FormRequest
{
    public const QUESTIONS = [
        'age', 'horizon', 'goal', 'drop_reaction', 'experience',
        'liquidity', 'target_return', 'contributions', 'base_currency', 'shariah',
    ];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $rules = [];

        // Matches the web questionnaire's validation exactly: every answer is
        // 1-4, even though the "shariah" question only offers 3 options in
        // the UI — a 4 falls through to the same "no requirement" branch as
        // a 3 in submit()'s mapping below, so this is not a bug to tighten.
        foreach (self::QUESTIONS as $key) {
            $rules["answers.$key"] = ['required', 'integer', 'between:1,4'];
        }

        return $rules;
    }

    /**
     * @return array<string, int>
     */
    public function answers(): array
    {
        return $this->validated('answers');
    }
}
