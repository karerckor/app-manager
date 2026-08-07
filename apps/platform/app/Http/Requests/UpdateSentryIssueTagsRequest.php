<?php

namespace App\Http\Requests;

use App\Support\Tags\TagRules;
use Illuminate\Foundation\Http\FormRequest;

class UpdateSentryIssueTagsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return TagRules::rules();
    }

    /** @return list<array{0: string, 1?: string|null, 2?: string|null}> */
    public function normalizedTags(): array
    {
        return TagRules::normalize($this->validated('tags') ?? []);
    }
}
