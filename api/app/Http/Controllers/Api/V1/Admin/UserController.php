<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Commerce\Models\Order;
use App\Domain\Identity\Actions\ChangeUserStatus;
use App\Domain\Identity\Actions\RevokeDevice;
use App\Domain\Identity\Actions\SignOutEverywhere;
use App\Domain\Identity\Models\Device;
use App\Domain\Identity\Models\User;
use App\Domain\Learning\Models\Enrollment;
use App\Domain\Platform\Actions\RecordAudit;
use App\Domain\Shared\ValueObjects\PhoneNumber;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UserStatusRequest;
use App\Http\Resources\Admin\AdminUserDetailResource;
use App\Http\Resources\Admin\AdminUserResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;
use Throwable;

/**
 * Accounts in the back office (PRD-09 FR-04): find by name or phone, see one, suspend / ban / reactivate, sign out
 * everywhere, remove a device. Every action is audited.
 */
final class UserController extends Controller
{
    #[\Dedoc\Scramble\Attributes\QueryParameter('page', type: 'int', default: 1)]
    #[\Dedoc\Scramble\Attributes\QueryParameter('per_page', type: 'int', default: 15)]
    #[\Dedoc\Scramble\Attributes\QueryParameter('filter[q]', description: 'A phone number, or part of a name.', type: 'string')]
    #[\Dedoc\Scramble\Attributes\QueryParameter('filter[status]', description: '`active`, `suspended` or `banned`.', type: 'string')]
    #[\Dedoc\Scramble\Attributes\QueryParameter('filter[role]', description: '`student`, `professor`, `moderator`, `admin` or `super_admin`.', type: 'string')]
    #[\Dedoc\Scramble\Attributes\QueryParameter('sort', description: '`created_at` or `last_seen_at`; prefix `-` for newest first (default `-created_at`).', type: 'string')]
    public function index(Request $request): AnonymousResourceCollection
    {
        $users = QueryBuilder::for(User::query()->with('roles'))
            ->allowedFilters(
                AllowedFilter::exact('status'),
                AllowedFilter::callback('role', fn (Builder $q, $role) => $q->whereHas('roles', fn (Builder $r) => $r->where('name', (string) $role))),
                AllowedFilter::callback('q', fn (Builder $q, $term) => $this->search($q, (string) $term)),
            )
            ->allowedSorts('created_at', 'last_seen_at')
            ->defaultSort('-created_at')
            ->paginate($this->perPage($request))
            ->appends($request->query());

        return AdminUserResource::collection($users);
    }

    public function show(User $user): AdminUserDetailResource
    {
        $user->load('roles', 'province');
        $enrollments = Enrollment::query()->with('course.liveVersion')->where('user_id', $user->id);

        return new AdminUserDetailResource(
            $user,
            Device::query()->active()->where('user_id', $user->id)->orderByDesc('last_active_at')->get(),
            (clone $enrollments)->orderByDesc('enrolled_at')->limit(10)->get(),
            (clone $enrollments)->count(),
            Order::query()->where(fn (Builder $q) => $q->where('beneficiary_id', $user->id)->orWhere('buyer_id', $user->id))
                ->orderByDesc('created_at')->limit(10)->get(),
            $user->professorProfile()->value('id'),
        );
    }

    /**
     * Suspend (may sign in to read why), ban (may not sign in) or reactivate; suspending or banning signs the account
     * out everywhere. A reason (`note`) is required.
     */
    public function status(UserStatusRequest $request, User $user, ChangeUserStatus $change): AdminUserDetailResource
    {
        $change->handle($user, $request->status(), (string) $request->input('note'), $this->staff($request));

        return $this->show($user->refresh());
    }

    /**
     * Ends every session and app token of the account (e.g. a lost phone).
     */
    public function signOut(Request $request, User $user, SignOutEverywhere $signOut, RecordAudit $recordAudit): AdminUserDetailResource
    {
        $staff = $this->staff($request);
        ChangeUserStatus::assertMayManage($user, $staff);
        $signOut->handle($user);
        $recordAudit->handle('user.signed_out_everywhere', $user, causer: $staff);

        return $this->show($user->refresh());
    }

    /**
     * Removes one registered device: it is signed out and its downloads stop at its next sync.
     */
    public function removeDevice(Request $request, User $user, Device $device, RevokeDevice $revoke, RecordAudit $recordAudit): AdminUserDetailResource
    {
        abort_unless($device->user_id === $user->id, 404);
        $staff = $this->staff($request);
        ChangeUserStatus::assertMayManage($user, $staff);
        $revoke->handle($device);
        $recordAudit->handle('user.device_removed', $user, ['device_id' => $device->id], causer: $staff);

        return $this->show($user->refresh());
    }

    /** @param  Builder<Model>  $query */
    private function search(Builder $query, string $term): void
    {
        $term = trim($term);
        try {
            $query->where('phone_e164', PhoneNumber::fromString($term)->e164);

            return;
        } catch (Throwable) {
            // not a phone number: search names
        }
        $query->where('name', 'ilike', '%'.addcslashes($term, '%_\\').'%');
    }

    private function staff(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
