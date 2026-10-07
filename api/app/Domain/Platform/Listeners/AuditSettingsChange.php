<?php

declare(strict_types=1);

namespace App\Domain\Platform\Listeners;

use App\Domain\Platform\Actions\RecordAudit;
use Spatie\LaravelSettings\Events\SavingSettings;

/**
 * Every platform settings change goes to the audit log with before/after values (project-context → Platform settings).
 */
final class AuditSettingsChange
{
    public function __construct(private readonly RecordAudit $recordAudit) {}

    public function handle(SavingSettings $event): void
    {
        $before = $event->originalValues?->all() ?? [];
        $after = $event->properties->all();

        $changed = array_keys(array_filter(
            $after,
            fn (mixed $value, string $key) => ! array_key_exists($key, $before) || $before[$key] !== $value,
            ARRAY_FILTER_USE_BOTH,
        ));

        if ($changed === []) {
            return;
        }

        $group = $event->settings::group();

        $this->recordAudit->handle(
            event: 'settings.updated',
            properties: [
                'group' => $group,
                'before' => array_intersect_key($before, array_flip($changed)),
                'after' => array_intersect_key($after, array_flip($changed)),
            ],
            subjectLabel: 'settings:'.$group,
        );
    }
}
