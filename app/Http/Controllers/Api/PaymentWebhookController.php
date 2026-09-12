<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ApiResponseTrait;
use App\Http\Controllers\Controller;
use App\Services\Payment\PaymentWebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentWebhookController extends Controller
{
    use ApiResponseTrait;

    public function __construct(private readonly PaymentWebhookService $webhookService) {}

    public function handle(Request $request, string $provider): JsonResponse
    {
        $result = $this->webhookService->processWebhook($request, $provider);

        if (! $result->success) {
            return response()->json([
                'success' => false,
                'message' => $result->message,
                'errors' => $result->errors ?? (object) [],
            ], $result->statusCode);
        }

        return response()->json([
            'success' => true,
            'message' => $result->message,
            'data' => $result->data,
        ], $result->statusCode);
    }
}
