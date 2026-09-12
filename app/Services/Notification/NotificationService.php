<?php

namespace App\Services\Notification;

use App\Models\Notification;
use App\Models\NotificationTemplate;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;

class NotificationService
{
    /**
     * Create a notification record directly.
     *
     * @param  array<string, mixed>|null  $data
     */
    public function createNotification(
        User $user,
        string $type,
        string $title,
        string $message,
        ?array $data = null,
        ?Model $related = null,
        ?DateTimeInterface $sentAt = null
    ): Notification {
        return Notification::query()->create([
            'user_id' => $user->id,
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'data' => $data,
            'related_type' => $related ? get_class($related) : null,
            'related_id' => $related ? $related->getKey() : null,
            'read_at' => null,
            'sent_at' => $sentAt ?? now(),
        ]);
    }

    /**
     * Create a notification by looking up and rendering a NotificationTemplate.
     * If no template exists for the given type, fall back to safe default rendering.
     *
     * @param  array<string, mixed>  $variables
     * @param  array<string, mixed>|null  $data
     */
    public function createFromTemplate(
        User $user,
        string $type,
        array $variables = [],
        ?Model $related = null,
        ?array $data = null
    ): Notification {
        $template = NotificationTemplate::query()
            ->where('notification_type', $type)
            ->where('status', 'active')
            ->first();

        if ($template) {
            $title = $this->renderTemplate($template->subject ?? $template->name, $variables);
            $message = $this->renderTemplate($template->body, $variables);
        } else {
            $title = $variables['title'] ?? ucfirst(str_replace('.', ' ', $type));
            $message = $this->fallbackMessageForType($type, $variables);
        }

        return $this->createNotification($user, $type, $title, $message, $data, $related);
    }

    /**
     * Safely render a template string replacing {{variable}} placeholders.
     * Unsupported or missing variables leave placeholders intact or handle safely.
     *
     * @param  array<string, mixed>  $variables
     */
    public function renderTemplate(string $templateText, array $variables): string
    {
        return preg_replace_callback('/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/', function (array $matches) use ($variables): string {
            $key = $matches[1];

            if (array_key_exists($key, $variables)) {
                $value = $variables[$key];

                if (is_scalar($value) || (is_object($value) && method_exists($value, '__toString'))) {
                    return (string) $value;
                }
            }

            return $matches[0];
        }, $templateText);
    }

    /**
     * Retrieve paginated notifications for the given user with optional filters.
     *
     * @param  array{unread?: bool|string, type?: string}  $filters
     * @return LengthAwarePaginator<Notification>
     */
    public function getNotifications(User $user, array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        $query = $user->appNotifications();

        if (! empty($filters['unread']) && filter_var($filters['unread'], FILTER_VALIDATE_BOOLEAN)) {
            $query->unread();
        }

        if (! empty($filters['type'])) {
            $query->ofType((string) $filters['type']);
        }

        return $query->latest('created_at')->paginate($perPage);
    }

    /**
     * Get unread notification count for the user.
     */
    public function getUnreadCount(User $user): int
    {
        return $user->appNotifications()->unread()->count();
    }

    /**
     * Mark a notification as read (idempotent).
     */
    public function markAsRead(Notification $notification): Notification
    {
        if ($notification->read_at === null) {
            $notification->update(['read_at' => now()]);
        }

        return $notification->fresh();
    }

    /**
     * Mark a notification as unread (idempotent).
     */
    public function markAsUnread(Notification $notification): Notification
    {
        if ($notification->read_at !== null) {
            $notification->update(['read_at' => null]);
        }

        return $notification->fresh();
    }

    /**
     * Mark all unread notifications of the user as read.
     */
    public function markAllAsRead(User $user): int
    {
        return $user->appNotifications()->unread()->update(['read_at' => now()]);
    }

    /**
     * Generate fallback text if no DB template is registered.
     *
     * @param  array<string, mixed>  $variables
     */
    private function fallbackMessageForType(string $type, array $variables): string
    {
        if (isset($variables['message'])) {
            return (string) $variables['message'];
        }

        return match ($type) {
            NotificationType::PAYMENT_REMINDER => 'Your payment of RM'.($variables['amount'] ?? '0.00').' for '.($variables['class_name'] ?? 'your class').' is due on '.($variables['due_date'] ?? 'soon').'.',
            NotificationType::PAYMENT_SUCCESS => 'Your payment of RM'.($variables['amount'] ?? '0.00').' was successful.',
            NotificationType::PAYMENT_FAILED => 'Your payment of RM'.($variables['amount'] ?? '0.00').' failed.',
            NotificationType::PAYMENT_OVERDUE => 'Your payment of RM'.($variables['amount'] ?? '0.00').' is overdue.',
            NotificationType::CLASS_ADDED => 'You have been enrolled in '.($variables['class_name'] ?? 'a new class').'.',
            NotificationType::CLASS_REMOVED => 'You have been removed from '.($variables['class_name'] ?? 'the class').'.',
            default => "Notification regarding {$type}.",
        };
    }
}
