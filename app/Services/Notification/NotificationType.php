<?php

namespace App\Services\Notification;

class NotificationType
{
    public const PAYMENT_REMINDER = 'payment.reminder';

    public const PAYMENT_SUCCESS = 'payment.success';

    public const PAYMENT_FAILED = 'payment.failed';

    public const PAYMENT_OVERDUE = 'payment.overdue';

    public const PAYMENT_PENDING = 'payment.pending';

    public const CLASS_ADDED = 'class.added';

    public const CLASS_REMOVED = 'class.removed';

    public const SYSTEM_NOTIFICATION = 'system.notification';

    /**
     * @return array<string>
     */
    public static function all(): array
    {
        return [
            self::PAYMENT_REMINDER,
            self::PAYMENT_SUCCESS,
            self::PAYMENT_FAILED,
            self::PAYMENT_OVERDUE,
            self::PAYMENT_PENDING,
            self::CLASS_ADDED,
            self::CLASS_REMOVED,
            self::SYSTEM_NOTIFICATION,
        ];
    }
}
