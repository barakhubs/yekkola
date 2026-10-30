<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Domain\Identity\Enums\Permission;
use App\Domain\Identity\Enums\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** A staff member's role and extra permissions (PRD-09 FR-17). */
class StaffRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'role' => ['required', Rule::in([Role::Moderator->value, Role::Admin->value, Role::SuperAdmin->value])],
            /** Extra permissions on top of the role's (e.g. finance). */
            'permissions' => ['present', 'array'],
            'permissions.*' => ['distinct', Rule::enum(Permission::class)],
        ];
    }

    public function role(): Role
    {
        return Role::from($this->string('role')->toString());
    }

    /**
     * @return list<Permission>
     */
    public function permissions(): array
    {
        /** @var list<string> $values */
        $values = $this->input('permissions', []);

        return array_map(fn (string $p) => Permission::from($p), $values);
    }
}
