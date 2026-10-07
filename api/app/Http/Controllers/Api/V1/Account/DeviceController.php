<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Account;

use App\Domain\Identity\Actions\RevokeDevice;
use App\Domain\Identity\Exceptions\IdentityException;
use App\Domain\Identity\Models\Device;
use App\Domain\Identity\Models\User;
use App\Http\Controllers\Controller;
use App\Http\Resources\DeviceResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

final class DeviceController extends Controller
{
    /**
     * Registered mobile devices (those that can hold downloads).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();

        return DeviceResource::collection($user->devices()->active()->orderByDesc('last_active_at')->get());
    }

    /**
     * Remove a device: it is signed out and its downloads stop being renewed.
     */
    public function destroy(Request $request, string $device, RevokeDevice $revokeDevice): Response
    {
        /** @var User $user */
        $user = $request->user();

        $model = Device::query()->where('user_id', $user->id)->active()->find($device)
            ?? throw IdentityException::deviceNotFound();

        $revokeDevice->handle($model);

        return response()->noContent();
    }
}
