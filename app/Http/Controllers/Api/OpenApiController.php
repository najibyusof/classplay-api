<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;
use Illuminate\View\View;

class OpenApiController
{
    public function ui(): View
    {
        return view('swagger');
    }

    public function specification(Request $request): JsonResponse
    {
        $paths = [];

        foreach (Route::getRoutes() as $route) {
            if (! $this->isDocumentedApiRoute($route)) {
                continue;
            }

            $path = $this->openApiPath($route->uri());
            $methods = array_filter($route->methods(), fn (string $method): bool => $method !== 'HEAD');

            foreach ($methods as $method) {
                $operation = [
                    'operationId' => $this->operationId($route, $method),
                    'summary' => $this->summary($route),
                    'tags' => [$this->tag($route)],
                    'responses' => $this->responses($route),
                ];

                if ($this->requiresAuthentication($route)) {
                    $operation['security'] = [['sanctumBearer' => []]];
                }

                $parameters = $this->parameters($route);
                if ($parameters !== []) {
                    $operation['parameters'] = $parameters;
                }

                if (in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
                    $operation['requestBody'] = $this->requestBody($route);
                }

                $paths[$path][strtolower($method)] = $operation;
            }
        }

        ksort($paths);

        return response()->json([
            'openapi' => '3.0.3',
            'info' => [
                'title' => config('app.name', 'ClassPay').' API',
                'description' => 'ClassPay versioned REST API documentation generated from the registered Laravel routes.',
                'version' => '1.0.0',
            ],
            'servers' => [['url' => rtrim($request->getSchemeAndHttpHost(), '/').'/api/v1']],
            'tags' => $this->tags($paths),
            'paths' => $paths,
            'components' => [
                'schemas' => $this->schemas(),
                'securitySchemes' => [
                    'sanctumBearer' => [
                        'type' => 'http',
                        'scheme' => 'bearer',
                        'bearerFormat' => 'Sanctum token',
                        'description' => 'Use the token returned by POST /api/v1/auth/login.',
                    ],
                ],
            ],
        ]);
    }

    private function isDocumentedApiRoute(LaravelRoute $route): bool
    {
        return str_starts_with($route->uri(), 'api/v1/') && $route->getName() !== null;
    }

    private function openApiPath(string $uri): string
    {
        return '/'.preg_replace('/\{([^}]+)\??}/', '{$1}', preg_replace('/^api\/v1\//', '', $uri));
    }

    private function operationId(LaravelRoute $route, string $method): string
    {
        return strtolower($method).'_'.str_replace(['.', '-', '{', '}'], '_', (string) $route->getName());
    }

    private function summary(LaravelRoute $route): string
    {
        $name = (string) $route->getName();

        return ucwords(str_replace(['.', '-', '_'], ' ', preg_replace('/^v1\./', '', $name)));
    }

    private function tag(LaravelRoute $route): string
    {
        $name = (string) $route->getName();
        $segments = explode('.', preg_replace('/^v1\./', '', $name));

        return ucwords(str_replace(['-', '_'], ' ', $segments[0] ?? 'API'));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function parameters(LaravelRoute $route): array
    {
        $parameters = [];

        foreach ($route->parameterNames() as $name) {
            $parameters[] = [
                'name' => $name,
                'in' => 'path',
                'required' => true,
                'schema' => ['type' => 'string'],
            ];
        }

        foreach ($this->queryParameters($route) as $parameter) {
            $parameters[] = $parameter;
        }

        return $parameters;
    }

    /**
     * @return array<string, mixed>
     */
    private function requestBody(LaravelRoute $route): array
    {
        $name = (string) $route->getName();
        $schema = match (true) {
            str_ends_with($name, 'auth.forgot-password') => ['$ref' => '#/components/schemas/ForgotPasswordRequest'],
            str_ends_with($name, 'auth.reset-password') => ['$ref' => '#/components/schemas/ResetPasswordRequest'],
            str_ends_with($name, 'auth.register') => ['$ref' => '#/components/schemas/RegisterRequest'],
            str_ends_with($name, 'auth.login') => ['$ref' => '#/components/schemas/LoginRequest'],
            str_ends_with($name, 'auth.change-password') => ['$ref' => '#/components/schemas/ChangePasswordRequest'],
            str_ends_with($name, 'auth.set-password') => ['$ref' => '#/components/schemas/SetPasswordRequest'],
            str_contains($name, 'payments.store') => ['$ref' => '#/components/schemas/StorePaymentRequest'],
            str_contains($name, 'payments.initiate') => ['$ref' => '#/components/schemas/EmptyRequest'],
            str_contains($name, 'payment.webhook') || str_contains($name, 'webhooks.payments') => ['$ref' => '#/components/schemas/PaymentWebhookRequest'],
            str_contains($name, 'payments.proofs.store') => ['$ref' => '#/components/schemas/PaymentProofUploadRequest'],
            str_contains($name, 'payment-setting.qr-code') => ['$ref' => '#/components/schemas/QrCodeUploadRequest'],
            str_contains($name, 'devices.store') || str_contains($name, 'user-devices.store') => ['$ref' => '#/components/schemas/StoreDeviceRequest'],
            str_contains($name, 'classes.store') => ['$ref' => '#/components/schemas/StoreClassRequest'],
            str_contains($name, 'classes.update') => ['$ref' => '#/components/schemas/UpdateClassRequest'],
            str_contains($name, 'payment-setting.store') => ['$ref' => '#/components/schemas/StorePaymentSettingRequest'],
            str_contains($name, 'payment-schedules.store') => ['$ref' => '#/components/schemas/StorePaymentScheduleRequest'],
            str_contains($name, 'participants.store') => ['$ref' => '#/components/schemas/StoreParticipantRequest'],
            str_contains($name, 'organizations.store') => ['$ref' => '#/components/schemas/StoreOrganizationRequest'],
            str_contains($name, 'students.store') => ['$ref' => '#/components/schemas/StorePersonRequest'],
            str_contains($name, 'sponsors.store') => ['$ref' => '#/components/schemas/StorePersonRequest'],
            default => ['type' => 'object', 'additionalProperties' => true],
        };

        $isMultipart = str_contains($name, 'payments.proofs.store') || str_contains($name, 'payment-setting.qr-code');

        return [
            'required' => ! in_array($name, ['v1.auth.logout', 'v1.auth.refresh-token'], true),
            'content' => [
                $isMultipart ? 'multipart/form-data' : 'application/json' => ['schema' => $schema],
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function queryParameters(LaravelRoute $route): array
    {
        $name = (string) $route->getName();
        $parameters = [];

        if (in_array('GET', $route->methods(), true)) {
            $parameters = [
                ['name' => 'page', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1, 'default' => 1]],
                ['name' => 'per_page', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20]],
            ];
        }

        if (str_contains($name, 'payments.index') || str_contains($name, 'reports.')) {
            $parameters = array_merge($parameters, [
                ['name' => 'organization_id', 'in' => 'query', 'schema' => ['type' => 'integer']],
                ['name' => 'class_id', 'in' => 'query', 'schema' => ['type' => 'integer']],
                ['name' => 'participant_id', 'in' => 'query', 'schema' => ['type' => 'integer']],
                ['name' => 'student_id', 'in' => 'query', 'schema' => ['type' => 'integer']],
                ['name' => 'sponsor_id', 'in' => 'query', 'schema' => ['type' => 'integer']],
                ['name' => 'status', 'in' => 'query', 'schema' => ['type' => 'string']],
                ['name' => 'payment_method', 'in' => 'query', 'schema' => ['type' => 'string']],
                ['name' => 'from', 'in' => 'query', 'schema' => ['type' => 'string', 'format' => 'date']],
                ['name' => 'to', 'in' => 'query', 'schema' => ['type' => 'string', 'format' => 'date']],
            ]);
        }

        if (str_contains($name, 'notifications.index')) {
            $parameters = array_merge($parameters, [
                ['name' => 'unread', 'in' => 'query', 'schema' => ['type' => 'boolean']],
                ['name' => 'type', 'in' => 'query', 'schema' => ['type' => 'string']],
            ]);
        }

        return $parameters;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function schemas(): array
    {
        $string = fn (string $description = ''): array => ['type' => 'string', ...($description ? ['description' => $description] : [])];
        $number = fn (): array => ['type' => 'number', 'format' => 'double', 'minimum' => 0];

        return [
            'ApiSuccessResponse' => [
                'type' => 'object',
                'required' => ['success', 'message', 'data'],
                'properties' => [
                    'success' => ['type' => 'boolean', 'example' => true],
                    'message' => ['type' => 'string', 'example' => 'Request completed successfully.'],
                    'data' => ['type' => 'object', 'additionalProperties' => true],
                ],
            ],
            'ApiNullSuccessResponse' => [
                'type' => 'object',
                'required' => ['success', 'message', 'data'],
                'properties' => [
                    'success' => ['type' => 'boolean', 'example' => true],
                    'message' => ['type' => 'string', 'example' => 'Resource deleted successfully.'],
                    'data' => ['type' => 'null', 'example' => null],
                ],
            ],
            'ApiErrorResponse' => [
                'type' => 'object',
                'required' => ['success', 'message', 'errors'],
                'properties' => [
                    'success' => ['type' => 'boolean', 'example' => false],
                    'message' => ['type' => 'string', 'example' => 'The given data was invalid.'],
                    'errors' => ['type' => 'object', 'additionalProperties' => ['type' => 'array', 'items' => ['type' => 'string']]],
                ],
            ],
            'ForgotPasswordRequest' => [
                'type' => 'object',
                'required' => ['email'],
                'properties' => [
                    'email' => ['type' => 'string', 'format' => 'email', 'example' => 'user@example.com'],
                ],
            ],
            'ResetPasswordRequest' => [
                'type' => 'object',
                'required' => ['token', 'email', 'password', 'password_confirmation'],
                'properties' => [
                    'token' => ['type' => 'string', 'example' => 'reset-token-from-email'],
                    'email' => ['type' => 'string', 'format' => 'email', 'example' => 'user@example.com'],
                    'password' => ['type' => 'string', 'format' => 'password', 'minLength' => 8],
                    'password_confirmation' => ['type' => 'string', 'format' => 'password'],
                ],
            ],
            'AuthResponse' => [
                'allOf' => [
                    ['$ref' => '#/components/schemas/ApiSuccessResponse'],
                    [
                        'type' => 'object',
                        'properties' => [
                            'data' => [
                                'type' => 'object',
                                'required' => ['user', 'token', 'token_type'],
                                'properties' => [
                                    'user' => ['$ref' => '#/components/schemas/User'],
                                    'token' => ['type' => 'string', 'example' => '1|sanctum-token'],
                                    'token_type' => ['type' => 'string', 'example' => 'Bearer'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'User' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer', 'example' => 1],
                    'name' => ['type' => 'string', 'example' => 'Ahmad Ali'],
                    'phone' => ['type' => 'string', 'example' => '+60123456789'],
                    'email' => ['type' => 'string', 'format' => 'email', 'nullable' => true, 'example' => 'ahmad@example.com'],
                    'user_type' => ['type' => 'string', 'enum' => ['admin', 'student', 'sponsor']],
                    'status' => ['type' => 'string', 'enum' => ['active', 'inactive', 'suspended']],
                    'phone_verified_at' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true],
                    'last_login_at' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true],
                ],
            ],
            'PaginatedResponse' => [
                'allOf' => [
                    ['$ref' => '#/components/schemas/ApiSuccessResponse'],
                    [
                        'type' => 'object',
                        'properties' => [
                            'data' => [
                                'type' => 'object',
                                'required' => ['pagination'],
                                'properties' => [
                                    'pagination' => ['$ref' => '#/components/schemas/Pagination'],
                                ],
                                'additionalProperties' => true,
                            ],
                        ],
                    ],
                ],
            ],
            'Pagination' => [
                'type' => 'object',
                'required' => ['current_page', 'per_page', 'total', 'last_page'],
                'properties' => [
                    'current_page' => ['type' => 'integer', 'example' => 1],
                    'per_page' => ['type' => 'integer', 'example' => 20],
                    'total' => ['type' => 'integer', 'example' => 42],
                    'last_page' => ['type' => 'integer', 'example' => 3],
                ],
            ],
            'HealthResponse' => [
                'allOf' => [
                    ['$ref' => '#/components/schemas/ApiSuccessResponse'],
                    [
                        'type' => 'object',
                        'properties' => [
                            'data' => [
                                'type' => 'object',
                                'properties' => [
                                    'status' => ['type' => 'string', 'example' => 'ok'],
                                    'timestamp' => ['type' => 'string', 'format' => 'date-time'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'ClassResponse' => [
                'allOf' => [
                    ['$ref' => '#/components/schemas/ApiSuccessResponse'],
                    [
                        'type' => 'object',
                        'properties' => [
                            'data' => [
                                'type' => 'object',
                                'properties' => [
                                    'id' => ['type' => 'integer', 'example' => 10],
                                    'organization_id' => ['type' => 'integer', 'example' => 1],
                                    'name' => ['type' => 'string', 'example' => 'Quran Class'],
                                    'description' => ['type' => 'string', 'nullable' => true],
                                    'teacher_name' => ['type' => 'string', 'example' => 'Cikgu Ahmad'],
                                    'day_of_week' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 6, 'example' => 1],
                                    'start_time' => ['type' => 'string', 'example' => '10:00:00'],
                                    'frequency' => ['type' => 'string', 'enum' => ['weekly', 'fortnightly', 'monthly']],
                                    'payment_amount' => ['type' => 'string', 'example' => '50.00'],
                                    'status' => ['type' => 'string', 'enum' => ['draft', 'active', 'inactive', 'completed']],
                                    'start_date' => ['type' => 'string', 'format' => 'date', 'nullable' => true],
                                    'end_date' => ['type' => 'string', 'format' => 'date', 'nullable' => true],
                                    'created_by' => ['type' => 'integer', 'nullable' => true],
                                    'schedules' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/ClassSchedule']],
                                    'payment_setting' => ['$ref' => '#/components/schemas/ClassPaymentSetting'],
                                    'created_at' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true],
                                    'updated_at' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'ClassSchedule' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer', 'example' => 1],
                    'class_id' => ['type' => 'integer', 'example' => 10],
                    'day_of_week' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 6, 'example' => 1],
                    'start_time' => ['type' => 'string', 'example' => '10:00'],
                    'end_time' => ['type' => 'string', 'nullable' => true, 'example' => '11:30'],
                    'timezone' => ['type' => 'string', 'nullable' => true, 'example' => 'Asia/Kuala_Lumpur'],
                    'recurrence_type' => ['type' => 'string', 'enum' => ['weekly', 'fortnightly', 'monthly']],
                    'effective_from' => ['type' => 'string', 'format' => 'date', 'nullable' => true],
                    'effective_until' => ['type' => 'string', 'format' => 'date', 'nullable' => true],
                ],
            ],
            'ClassPaymentSetting' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer', 'example' => 1],
                    'class_id' => ['type' => 'integer', 'example' => 10],
                    'required_amount' => ['type' => 'string', 'example' => '50.00'],
                    'currency' => ['type' => 'string', 'example' => 'MYR'],
                    'payment_frequency' => ['type' => 'string', 'enum' => ['weekly', 'fortnightly', 'monthly']],
                    'bank_name' => ['type' => 'string', 'nullable' => true],
                    'bank_account_name' => ['type' => 'string', 'nullable' => true],
                    'bank_account_number' => ['type' => 'string', 'nullable' => true],
                    'qr_code_path' => ['type' => 'string', 'nullable' => true],
                    'merchant_payment_url' => ['type' => 'string', 'nullable' => true],
                    'allow_additional_infaq' => ['type' => 'boolean'],
                    'minimum_infaq' => ['type' => 'string', 'nullable' => true, 'example' => '5.00'],
                    'maximum_infaq' => ['type' => 'string', 'nullable' => true, 'example' => '100.00'],
                    'reminder_enabled' => ['type' => 'boolean'],
                    'reminder_days_before' => ['type' => 'integer', 'nullable' => true],
                    'reminder_days_after' => ['type' => 'integer', 'nullable' => true],
                ],
            ],
            'EmptyRequest' => ['type' => 'object', 'additionalProperties' => false],
            'LoginRequest' => ['type' => 'object', 'required' => ['phone', 'password', 'device_name'], 'properties' => ['phone' => $string('+60123456789'), 'password' => ['type' => 'string', 'format' => 'password'], 'device_name' => $string('Mobile device name')]],
            'RegisterRequest' => ['type' => 'object', 'required' => ['name', 'phone', 'password', 'password_confirmation'], 'properties' => ['name' => $string(), 'phone' => $string('+60123456789'), 'email' => ['type' => 'string', 'format' => 'email'], 'password' => ['type' => 'string', 'format' => 'password', 'minLength' => 8], 'password_confirmation' => ['type' => 'string', 'format' => 'password'], 'device_name' => $string('Mobile device name')]],
            'ChangePasswordRequest' => ['type' => 'object', 'required' => ['current_password', 'password', 'password_confirmation'], 'properties' => ['current_password' => ['type' => 'string', 'format' => 'password'], 'password' => ['type' => 'string', 'format' => 'password', 'minLength' => 8], 'password_confirmation' => ['type' => 'string', 'format' => 'password']]],
            'SetPasswordRequest' => ['type' => 'object', 'required' => ['password', 'password_confirmation'], 'properties' => ['password' => ['type' => 'string', 'format' => 'password', 'minLength' => 8], 'password_confirmation' => ['type' => 'string', 'format' => 'password']]],
            'StorePaymentRequest' => ['type' => 'object', 'required' => ['payment_method'], 'properties' => ['additional_infaq' => $number(), 'payment_method' => ['type' => 'string', 'enum' => ['qr', 'merchant', 'bank_transfer', 'manual']]]],
            'PaymentWebhookRequest' => ['type' => 'object', 'required' => ['gateway_reference', 'status'], 'properties' => ['gateway_reference' => $string(), 'transaction_reference' => $string(), 'status' => ['type' => 'string', 'enum' => ['paid', 'failed', 'success', 'successful', 'completed']], 'amount' => $number(), 'currency' => ['type' => 'string', 'example' => 'MYR'], 'event_id' => $string(), 'response_code' => $string(), 'response_message' => $string()]],
            'PaymentProofUploadRequest' => ['type' => 'object', 'required' => ['file'], 'properties' => ['file' => ['type' => 'string', 'format' => 'binary', 'description' => 'JPG, JPEG, PNG, or WEBP image; max 5 MB']]],
            'QrCodeUploadRequest' => ['type' => 'object', 'required' => ['qr_code'], 'properties' => ['qr_code' => ['type' => 'string', 'format' => 'binary', 'description' => 'JPG, JPEG, PNG, or WEBP image; max 2 MB']]],
            'StoreDeviceRequest' => ['type' => 'object', 'required' => ['device_token', 'platform'], 'properties' => ['device_token' => $string(), 'platform' => ['type' => 'string', 'enum' => ['android', 'ios']], 'device_name' => $string(), 'app_version' => $string()]],
            'StoreOrganizationRequest' => ['type' => 'object', 'required' => ['name'], 'properties' => ['name' => $string(), 'code' => $string(), 'description' => $string(), 'logo_path' => $string(), 'status' => ['type' => 'string', 'enum' => ['active', 'inactive']]]],
            'StoreClassRequest' => ['type' => 'object', 'required' => ['name', 'teacher_name', 'day_of_week', 'start_time', 'recurrence_type', 'payment_amount'], 'properties' => ['name' => $string('e.g. Quran Class'), 'description' => $string(), 'teacher_name' => $string('e.g. Cikgu Ahmad'), 'status' => ['type' => 'string', 'enum' => ['draft', 'active', 'inactive', 'completed']], 'start_date' => ['type' => 'string', 'format' => 'date'], 'end_date' => ['type' => 'string', 'format' => 'date'], 'day_of_week' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 6, 'description' => '0 = Sunday, 1 = Monday, ... 6 = Saturday. Stored on the class and used to create the first class schedule.', 'example' => 1], 'start_time' => ['type' => 'string', 'format' => 'HH:MM', 'description' => 'Stored on the class and used to create the first class schedule.', 'example' => '10:00'], 'recurrence_type' => ['type' => 'string', 'enum' => ['weekly', 'fortnightly', 'monthly'], 'description' => 'Stored on the class as its frequency; also seeds the first class schedule and the payment frequency'], 'payment_amount' => ['type' => 'number', 'format' => 'double', 'minimum' => 0, 'description' => 'Required payment amount in MYR; stored on the class and copied to the payment setting', 'example' => 50.00]]],
            'UpdateClassRequest' => ['type' => 'object', 'properties' => ['name' => $string(), 'description' => $string(), 'teacher_name' => $string(), 'status' => ['type' => 'string', 'enum' => ['draft', 'active', 'inactive', 'completed']], 'start_date' => ['type' => 'string', 'format' => 'date'], 'end_date' => ['type' => 'string', 'format' => 'date']]],
            'StorePaymentSettingRequest' => ['type' => 'object', 'required' => ['required_amount', 'payment_frequency'], 'properties' => ['required_amount' => $number(), 'currency' => ['type' => 'string', 'minLength' => 3, 'maxLength' => 3, 'example' => 'MYR'], 'payment_frequency' => ['type' => 'string', 'enum' => ['weekly', 'fortnightly', 'monthly']], 'bank_name' => $string(), 'bank_account_name' => $string(), 'bank_account_number' => $string(), 'allow_additional_infaq' => ['type' => 'boolean'], 'minimum_infaq' => $number(), 'maximum_infaq' => $number(), 'reminder_enabled' => ['type' => 'boolean'], 'reminder_days_before' => ['type' => 'integer', 'minimum' => 0], 'reminder_days_after' => ['type' => 'integer', 'minimum' => 0]]],
            'StorePaymentScheduleRequest' => ['type' => 'object', 'required' => ['class_participant_id', 'period_start', 'period_end', 'due_date', 'required_amount'], 'properties' => ['class_participant_id' => ['type' => 'integer'], 'period_start' => ['type' => 'string', 'format' => 'date'], 'period_end' => ['type' => 'string', 'format' => 'date'], 'due_date' => ['type' => 'string', 'format' => 'date'], 'required_amount' => $number(), 'status' => ['type' => 'string', 'enum' => ['upcoming', 'pending', 'partially_paid', 'paid', 'overdue', 'cancelled']]]],
            'StoreParticipantRequest' => ['type' => 'object', 'required' => ['user_id', 'participant_type'], 'properties' => ['user_id' => ['type' => 'integer'], 'participant_type' => ['type' => 'string', 'enum' => ['student', 'sponsor']]]],
            'StorePersonRequest' => ['type' => 'object', 'required' => ['name', 'phone'], 'properties' => ['name' => $string(), 'phone' => $string('+60123456789'), 'email' => ['type' => 'string', 'format' => 'email']]],
        ];
    }

    /**
     * @return array<string, array<string, array<string, string>>>
     */
    private function responses(LaravelRoute $route): array
    {
        $successStatus = $this->successStatus($route);
        $responses = [
            (string) $successStatus => [
                'description' => $this->successDescription($route, $successStatus),
                ...($successStatus !== 204 ? ['content' => ['application/json' => ['schema' => $this->successSchema($route)]]] : []),
            ],
            '401' => $this->errorResponse('Unauthenticated'),
            '403' => $this->errorResponse('Forbidden'),
            '404' => $this->errorResponse('Resource not found'),
            '422' => $this->errorResponse('Validation or business rule error'),
        ];

        return $responses;
    }

    /**
     * @return array<string, mixed>
     */
    private function errorResponse(string $description): array
    {
        return [
            'description' => $description,
            'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/ApiErrorResponse']]],
        ];
    }

    private function successStatus(LaravelRoute $route): int
    {
        $name = (string) $route->getName();

        if ($name === 'v1.auth.register' || str_ends_with($name, '.store')) {
            return 201;
        }

        if ($name === 'v1.payment-schedules.destroy') {
            return 204;
        }

        return 200;
    }

    private function successDescription(LaravelRoute $route, int $status): string
    {
        if ($status === 204) {
            return 'No content';
        }

        if ($status === 201) {
            return 'Resource created';
        }

        return 'Successful response';
    }

    /**
     * @return array<string, mixed>
     */
    private function successSchema(LaravelRoute $route): array
    {
        $name = (string) $route->getName();

        if ($name === 'v1.auth.login' || $name === 'v1.auth.register') {
            return ['$ref' => '#/components/schemas/AuthResponse'];
        }

        if ($name === 'v1.auth.me') {
            return ['$ref' => '#/components/schemas/ApiSuccessResponse'];
        }

        if ($name === 'v1.health') {
            return ['$ref' => '#/components/schemas/HealthResponse'];
        }

        if (str_contains($name, '.index') || str_ends_with($name, '.logs.index')) {
            return ['$ref' => '#/components/schemas/PaginatedResponse'];
        }

        if (str_ends_with($name, '.destroy') || str_ends_with($name, '.logout') || str_ends_with($name, '.read-all') || str_ends_with($name, '.forgot-password') || str_ends_with($name, '.reset-password')) {
            return ['$ref' => '#/components/schemas/ApiNullSuccessResponse'];
        }

        if (in_array($name, ['v1.organizations.classes.show', 'v1.organizations.classes.update', 'v1.organizations.classes.activate'], true)) {
            return ['$ref' => '#/components/schemas/ClassResponse'];
        }

        return ['$ref' => '#/components/schemas/ApiSuccessResponse'];
    }

    /**
     * @param  array<string, mixed>  $paths
     * @return array<int, array{name: string}>
     */
    private function tags(array $paths): array
    {
        $tags = [];

        foreach ($paths as $operations) {
            foreach ($operations as $operation) {
                $name = $operation['tags'][0] ?? 'API';
                $tags[$name] = ['name' => $name];
            }
        }

        return array_values($tags);
    }

    private function requiresAuthentication(LaravelRoute $route): bool
    {
        return in_array('auth:sanctum', $route->gatherMiddleware(), true);
    }
}
