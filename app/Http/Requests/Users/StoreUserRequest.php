<?php

namespace App\Http\Requests\Users;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Create a new employee. Authorization (Admin+) is handled by the route
 * `role:admin` middleware and the UserPolicy; which roles may be assigned is
 * constrained here against the actor's own rank.
 */
class StoreUserRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // Name is immutable after creation, so it is only set here.
            'name' => ['required', 'string', 'max:255'],
            // Username uniqueness spans soft-deleted users too — an archived
            // employee keeps their username in Sales / Login History.
            'username' => ['required', 'string', 'max:255', Rule::unique('users', 'username')],
            'role' => ['required', Rule::in($this->assignableRoleValues())],
            'password' => ['required', 'confirmed', Password::defaults()],
        ];
    }

    /**
     * The role string values the current actor is permitted to assign.
     *
     * @return list<string>
     */
    private function assignableRoleValues(): array
    {
        return array_map(
            fn ($role) => $role->value,
            $this->user()->role->assignableRoles(),
        );
    }
}
