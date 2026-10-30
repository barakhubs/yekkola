<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Domain\Identity\Models\User;
use App\Domain\Platform\Models\Activity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One audit log entry (PRD-09 FR-16).
 *
 * @mixin Activity
 */
final class AuditEntryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            /** @var string e.g. `refund.approved`, `settings.updated`. */
            'event' => (string) $this->event,
            /** @var string|null The kind of record acted on (e.g. `Refund`, `User`). */
            'subject_type' => $this->subject_type === null ? null : class_basename((string) $this->subject_type),
            /** @var string|null */
            'subject_id' => $this->subject_id === null ? null : (string) $this->subject_id,
            'causer' => $this->causerSummary(),
            /** Details: before/after, reason, amounts… */
            'details' => $this->details(),
            /** @var string|null */
            'ip' => $this->contextValue('ip'),
            'created_at' => $this->created_at?->toIso8601String() ?? '',
        ];
    }

    /**
     * @return array{id: string, name: string|null}|null
     */
    private function causerSummary(): ?array
    {
        $causer = $this->causer;

        return $causer instanceof User ? ['id' => $causer->id, 'name' => $causer->name] : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function details(): array
    {
        /** @var array<string, mixed> $properties */
        $properties = $this->properties?->toArray() ?? [];
        unset($properties['context']);

        return $properties;
    }

    private function contextValue(string $key): ?string
    {
        $value = $this->properties?->get('context')[$key] ?? null;

        return is_string($value) ? $value : null;
    }
}
