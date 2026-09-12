<?php

namespace App\Services\Payment;

final readonly class PaymentWebhookResult
{
    /**
     * @param  array<string, mixed>|null  $data
     * @param  array<string, mixed>|null  $errors
     */
    public function __construct(
        public bool $success,
        public int $statusCode,
        public string $message,
        public ?array $data = null,
        public ?array $errors = null,
    ) {}

    /**
     * @param  array<string, mixed>|null  $data
     */
    public static function ok(string $message = 'Webhook processed successfully.', ?array $data = null): self
    {
        return new self(
            success: true,
            statusCode: 200,
            message: $message,
            data: $data
        );
    }

    /**
     * @param  array<string, mixed>|null  $errors
     */
    public static function error(string $message, int $statusCode = 422, ?array $errors = null): self
    {
        return new self(
            success: false,
            statusCode: $statusCode,
            message: $message,
            errors: $errors
        );
    }
}
