<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ApiResponseTrait;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class HealthController extends Controller
{
    use ApiResponseTrait;

    public function check(Request $request): JsonResponse
    {
        try {
            DB::connection()->getPdo();

            return $this->successResponse([
                'status' => 'ok',
                'timestamp' => now()->toIso8601String(),
            ], 'Service is healthy.');
        } catch (Throwable) {
            return response()->json([
                'success' => false,
                'message' => 'Service is unhealthy.',
                'errors' => (object) [],
            ], 503);
        }
    }
}
