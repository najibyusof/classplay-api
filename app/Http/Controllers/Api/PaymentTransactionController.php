<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ApiResponseTrait;
use App\Http\Controllers\Controller;
use App\Http\Requests\Payment\StorePaymentTransactionRequest;
use App\Http\Resources\PaymentTransactionResource;
use App\Models\Payment;
use App\Models\PaymentTransaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentTransactionController extends Controller
{
    use ApiResponseTrait;

    public function index(Request $request, Payment $payment): JsonResponse
    {
        $this->authorize('view', $payment);

        $perPage = max(1, min((int) $request->integer('per_page', 20), 100));
        $transactions = $payment->transactions()->paginate($perPage);

        return $this->successResponse([
            'transactions' => PaymentTransactionResource::collection($transactions)->resolve(),
            'pagination' => [
                'current_page' => $transactions->currentPage(),
                'per_page' => $transactions->perPage(),
                'total' => $transactions->total(),
                'last_page' => $transactions->lastPage(),
            ],
        ], 'Payment transactions retrieved successfully.');
    }

    public function store(StorePaymentTransactionRequest $request, Payment $payment): JsonResponse
    {
        $this->authorize('create', [PaymentTransaction::class, $payment]);

        $transaction = $payment->transactions()->create([
            ...$request->validated(),
            'initiated_at' => now(),
        ]);

        return $this->successResponse(
            new PaymentTransactionResource($transaction),
            'Payment transaction created successfully.',
            201
        );
    }
}
