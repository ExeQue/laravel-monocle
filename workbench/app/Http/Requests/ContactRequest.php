<?php

declare(strict_types=1);

namespace Workbench\App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Workbench\App\Rules\Slug;

final class ContactRequest extends FormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:3'],
            'handle' => ['nullable', new Slug(max: 20)],
            'email' => ['required', 'email'],
            'message' => ['required', 'string', 'min:20'],
        ];
    }
}
