<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Account;

use App\Domain\Identity\Models\User;
use App\Domain\Notifications\Enums\NotificationChannel;
use App\Domain\Notifications\Enums\NotificationType;
use App\Domain\Notifications\Models\NotificationPreference;
use App\Http\Controllers\Controller;
use App\Http\Requests\Account\MarkNotificationsReadRequest;
use App\Http\Requests\Account\NotificationPreferencesRequest;
use App\Http\Resources\NotificationResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

/**
 * My notifications (PRD-10): the in-app feed, unread count, mark read (FR-03), and my channel preferences for the
 * events I may turn off (FR-05).
 */
final class NotificationController extends Controller
{
    #[\Dedoc\Scramble\Attributes\QueryParameter('page', type: 'int', default: 1)]
    #[\Dedoc\Scramble\Attributes\QueryParameter('per_page', type: 'int', default: 15)]
    #[\Dedoc\Scramble\Attributes\QueryParameter('filter[unread]', description: '`true`: unread only.', type: 'string')]
    public function index(Request $request): AnonymousResourceCollection
    {
        $filter = $request->query('filter');
        $unreadOnly = is_array($filter) && filter_var($filter['unread'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $notifications = $this->user($request)->notifications()
            ->when($unreadOnly, fn ($q) => $q->whereNull('read_at'))
            ->paginate($this->perPage($request))
            ->appends($request->query());

        return NotificationResource::collection($notifications);
    }

    /**
     * For the bell; cheap enough to poll.
     *
     * @return array{data: array{unread: int}}
     */
    public function unreadCount(Request $request): array
    {
        return ['data' => ['unread' => $this->user($request)->unreadNotifications()->count()]];
    }

    /**
     * Marks the given notifications read, or all of them when `ids` is left out.
     *
     * @return array{data: array{unread: int}}
     */
    public function markRead(MarkNotificationsReadRequest $request): array
    {
        $user = $this->user($request);
        /** @var list<string>|null $ids */
        $ids = $request->validated('ids');
        $user->unreadNotifications()->when($ids !== null, fn ($q) => $q->whereIn('id', $ids))->update(['read_at' => now()]);

        return $this->unreadCount($request);
    }

    /**
     * Every event I may turn off, with the channels it uses and whether each is on.
     *
     * @return array{data: list<array{type: string, channels: array<string, bool>}>}
     */
    public function preferences(Request $request): array
    {
        return ['data' => $this->preferenceRows($this->user($request))];
    }

    /**
     * Sets channels on or off; events that can't be turned off are refused (422).
     *
     * @return array{data: list<array{type: string, channels: array<string, bool>}>}
     */
    public function updatePreferences(NotificationPreferencesRequest $request): array
    {
        $user = $this->user($request);
        /** @var list<array{type: string, channel: string, enabled: bool}> $items */
        $items = $request->validated('preferences');

        DB::transaction(function () use ($user, $items): void {
            foreach ($items as $item) {
                NotificationPreference::query()->updateOrCreate(
                    ['user_id' => $user->id, 'type' => $item['type'], 'channel' => $item['channel']],
                    ['enabled' => (bool) $item['enabled']],
                );
            }
        });

        return ['data' => $this->preferenceRows($user)];
    }

    /**
     * @return list<array{type: string, channels: array<string, bool>}>
     */
    private function preferenceRows(User $user): array
    {
        $off = NotificationPreference::query()->where('user_id', $user->id)->where('enabled', false)->get()
            ->map(fn (NotificationPreference $p) => $p->type->value.':'.$p->channel->value)->all();

        $rows = [];
        foreach (NotificationType::cases() as $type) {
            if (! $type->canDisable()) {
                continue;
            }
            $channels = [];
            foreach (self::settableChannels($type) as $channel) {
                $channels[$channel->value] = ! in_array($type->value.':'.$channel->value, $off, true);
            }
            $rows[] = ['type' => $type->value, 'channels' => $channels];
        }

        return $rows;
    }

    /**
     * Channels a user can set for an event: its catalogue channels plus push (FCM comes next), never SMS.
     *
     * @return list<NotificationChannel>
     */
    public static function settableChannels(NotificationType $type): array
    {
        $channels = array_values(array_filter($type->channels(), fn (NotificationChannel $c) => $c !== NotificationChannel::Sms));

        return [...$channels, NotificationChannel::Push];
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
