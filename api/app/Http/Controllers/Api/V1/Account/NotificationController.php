<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Account;

use App\Domain\Identity\Models\User;
use App\Domain\Notifications\Actions\UpdateNotificationPreferences;
use App\Domain\Notifications\Enums\NotificationType;
use App\Domain\Notifications\Models\NotificationPreference;
use App\Http\Controllers\Controller;
use App\Http\Requests\Account\MarkNotificationsReadRequest;
use App\Http\Requests\Account\NotificationPreferencesRequest;
use App\Http\Resources\NotificationResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * My notifications (PRD-10): the in-app feed, unread count, mark read (FR-03), and my channel preferences for the
 * events I may turn off (FR-05).
 */
final class NotificationController extends Controller
{
    #[\Dedoc\Scramble\Attributes\QueryParameter('page', type: 'int', default: 1)]
    #[\Dedoc\Scramble\Attributes\QueryParameter('per_page', type: 'int', default: 15)]
    #[\Dedoc\Scramble\Attributes\QueryParameter('filter[unread]', description: '`true`: unread only.', type: 'string')]
    #[\Dedoc\Scramble\Attributes\QueryParameter('filter[app]', description: '`web` (default) or `admin` (staff alerts).', type: 'string')]
    public function index(Request $request): AnonymousResourceCollection
    {
        $filter = $request->query('filter');
        $unreadOnly = is_array($filter) && filter_var($filter['unread'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $notifications = $this->forApp($this->user($request)->notifications(), $request)
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
    #[\Dedoc\Scramble\Attributes\QueryParameter('filter[app]', description: '`web` (default) or `admin`.', type: 'string')]
    public function unreadCount(Request $request): array
    {
        return ['data' => ['unread' => $this->forApp($this->user($request)->unreadNotifications(), $request)->count()]];
    }

    /**
     * Each app shows its own notifications: staff alerts in the back office, the rest on the web app.
     *
     * @template TQuery of \Illuminate\Database\Eloquent\Builder<\Illuminate\Notifications\DatabaseNotification>|\Illuminate\Database\Eloquent\Relations\MorphMany<\Illuminate\Notifications\DatabaseNotification, User>
     *
     * @param  TQuery  $query
     * @return TQuery
     */
    private function forApp($query, Request $request)
    {
        $filter = $request->query('filter');
        $app = is_array($filter) && ($filter['app'] ?? null) === 'admin' ? 'admin' : 'web';
        $query->whereRaw("coalesce(data->>'app', 'web') = ?", [$app]);

        return $query;
    }

    /**
     * Marks the given notifications read, or all of this app's when `ids` is left out (`filter[app]` as for the list).
     *
     * @return array{data: array{unread: int}}
     */
    #[\Dedoc\Scramble\Attributes\QueryParameter('filter[app]', description: '`web` (default) or `admin`.', type: 'string')]
    public function markRead(MarkNotificationsReadRequest $request): array
    {
        $user = $this->user($request);
        /** @var list<string>|null $ids */
        $ids = $request->validated('ids');
        $this->forApp($user->unreadNotifications(), $request)->when($ids !== null, fn ($q) => $q->whereIn('id', $ids))->update(['read_at' => now()]);

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
    public function updatePreferences(NotificationPreferencesRequest $request, UpdateNotificationPreferences $update): array
    {
        $user = $this->user($request);
        /** @var list<array{type: string, channel: string, enabled: bool}> $items */
        $items = $request->validated('preferences');

        $update->handle($user, $items);

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
            foreach ($type->settableChannels() as $channel) {
                $channels[$channel->value] = ! in_array($type->value.':'.$channel->value, $off, true);
            }
            $rows[] = ['type' => $type->value, 'channels' => $channels];
        }

        return $rows;
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
