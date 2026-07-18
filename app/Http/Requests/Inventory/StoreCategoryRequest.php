<?php

namespace App\Http\Requests\Inventory;

use App\Rules\SpreadsheetSafeName;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCategoryRequest extends FormRequest
{
    /**
     * Second authorization layer (defense-in-depth) beyond the `role:admin`
     * route group — mirrors the `manage-inventory` gate.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('manage-inventory') ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', new SpreadsheetSafeName, Rule::unique('categories', 'name')],
        ];
    }
}
