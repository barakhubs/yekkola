<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Identity\Actions\ManageStaff;
use App\Domain\Identity\Enums\Permission;
use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Models\User;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\InviteStaffRequest;
use App\Http\Requests\Admin\StaffRequest;
use App\Http\Resources\Admin\StaffMemberResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Staff (PRD-09 FR-17): who has back-office access, invite by phone, set role and extra permissions, end access.
 */
final class StaffController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $staffRoles = array_values(array_map(fn (Role $r) => $r->value, array_filter(Role::cases(), fn (Role $r) => $r->isStaff())));
        $staff = User::query()->with('roles', 'permissions')->role($staffRoles)->orderBy('name')->orderBy('id')
            ->paginate($this->perPage($request));

        return StaffMemberResource::collection($staff);
    }

    /**
     * Gives an account (found or created from its phone number) a staff role. They sign in with an SMS code.
     */
    public function store(InviteStaffRequest $request, ManageStaff $staff): JsonResponse
    {
        $user = $staff->invite($request->phone(), $request->role(), $request->permissions(), $this->staff($request), app()->getLocale());

        return (new StaffMemberResource($user->load('roles', 'permissions')))->response()->setStatusCode(201);
    }

    public function update(StaffRequest $request, User $user, ManageStaff $staff): StaffMemberResource
    {
        return new StaffMemberResource($staff->update($user, $request->role(), $request->permissions(), $this->staff($request))->load('roles', 'permissions'));
    }

    /**
     * Ends the account's staff access (roles and extra permissions) and signs it out everywhere.
     */
    public function destroy(Request $request, User $user, ManageStaff $staff): StaffMemberResource
    {
        return new StaffMemberResource($staff->remove($user, $this->staff($request))->load('roles', 'permissions'));
    }

    /**
     * Permissions that can be added on top of a role, for the staff form.
     *
     * @return array{data: list<string>}
     */
    public function permissions(): array
    {
        return ['data' => array_map(fn (Permission $p) => $p->value, Permission::cases())];
    }

    private function staff(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
