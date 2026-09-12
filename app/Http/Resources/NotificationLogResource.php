<?php

namespace App\Http\Resources;

use App\Models\NotificationLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin NotificationLog */
class NotificationLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'notification_id' => $this->notification_id,
            'channel' => $this->channel,
            'recipient' => $this->recipient,
            'status' => $this->status,
            'provider_message_id' => $this->provider_message_id,
            'sent_at' => $this->sent_at,
            'delivered_at' => $this->delivered_at,
            'error_message' => $this->error_message,
        ];
    }
}
