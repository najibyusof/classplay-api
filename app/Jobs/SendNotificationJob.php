<?php

namespace App\Jobs;

use App\Models\Notification;
use App\Services\NotificationDispatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [60, 300, 900];

    public int $notificationId;

    public function __construct(Notification|int $notification)
    {
        $this->notificationId = $notification instanceof Notification ? $notification->id : $notification;
    }

    public function handle(NotificationDispatcher $dispatcher): void
    {
        $notification = Notification::find($this->notificationId);

        if (! $notification) {
            return;
        }

        $dispatcher->dispatch($notification);
    }
}
