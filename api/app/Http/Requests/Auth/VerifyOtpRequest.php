<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Domain\Identity\Data\DeviceData;
use App\Domain\Identity\Enums\DevicePlatform;
use App\Http\Requests\Concerns\HasPhoneNumber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class VerifyOtpRequest extends FormRequest
{
    use HasPhoneNumber;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'phone' => ['required', 'string', 'max:32'],
            'code' => ['required', 'string', 'digits:6'],
            // Mobile apps send their device; web apps sign in with a session instead.
            'device' => ['nullable', 'array'],
            'device.install_id' => ['required_with:device', 'string', 'min:8', 'max:64'],
            'device.platform' => ['required_with:device', Rule::enum(DevicePlatform::class)],
            'device.name' => ['nullable', 'string', 'max:100'],
            'device.app_version' => ['nullable', 'string', 'max:32'],
            'device.push_token' => ['nullable', 'string', 'max:4096'],
            // After `device.limit_reached`: the device to sign out to make room.
            'replace_device_id' => ['nullable', 'ulid'],
        ];
    }

    public function deviceData(): ?DeviceData
    {
        if (! $this->filled('device')) {
            return null;
        }

        return new DeviceData(
            installId: (string) $this->input('device.install_id'),
            platform: DevicePlatform::from((string) $this->input('device.platform')),
            name: $this->input('device.name'),
            appVersion: $this->input('device.app_version'),
            pushToken: $this->input('device.push_token'),
        );
    }
}
