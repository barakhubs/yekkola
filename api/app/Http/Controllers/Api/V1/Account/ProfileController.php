<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Account;

use App\Domain\Identity\Actions\RequestAccountDeletion;
use App\Domain\Identity\Actions\UpdateProfile;
use App\Domain\Identity\Models\User;
use App\Http\Controllers\Controller;
use App\Http\Requests\Account\UpdateProfileRequest;
use App\Http\Resources\UserResource;
use Illuminate\Http\Request;

final class ProfileController extends Controller
{
    /**
     * The signed-in user's account.
     */
    public function show(Request $request): UserResource
    {
        return new UserResource($this->user($request)->load('province'));
    }

    /**
     * Update name, email, language, province, or city.
     */
    public function update(UpdateProfileRequest $request, UpdateProfile $updateProfile): UserResource
    {
        /** @var array{name?: string, email?: string|null, locale?: string, province_id?: string|null, city?: string|null} $data */
        $data = $request->validated();

        return new UserResource($updateProfile->handle($this->user($request), $data)->load('province'));
    }

    /**
     * Request account deletion. Personal data is erased after the grace period unless cancelled.
     */
    public function destroy(Request $request, RequestAccountDeletion $deletion): UserResource
    {
        return new UserResource($deletion->handle($this->user($request))->load('province'));
    }

    /**
     * Cancel a pending deletion request.
     */
    public function cancelDeletion(Request $request, RequestAccountDeletion $deletion): UserResource
    {
        return new UserResource($deletion->cancel($this->user($request))->load('province'));
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
