<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ApiResponseTrait;
use App\Http\Controllers\Controller;
use App\Http\Requests\Payment\StorePaymentProofRequest;
use App\Http\Requests\Payment\UpdatePaymentProofRequest;
use App\Http\Resources\PaymentProofResource;
use App\Models\Payment;
use App\Models\PaymentProof;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentProofController extends Controller
{
    use ApiResponseTrait;

    public function index(Request $request, Payment $payment): JsonResponse
    {
        $this->authorize('view', $payment);

        $perPage = max(1, min((int) $request->integer('per_page', 20), 100));
        $proofs = $payment->proofs()->paginate($perPage);

        return $this->successResponse([
            'proofs' => PaymentProofResource::collection($proofs)->resolve(),
            'pagination' => [
                'current_page' => $proofs->currentPage(),
                'per_page' => $proofs->perPage(),
                'total' => $proofs->total(),
                'last_page' => $proofs->lastPage(),
            ],
        ], 'Payment proofs retrieved successfully.');
    }

    public function store(StorePaymentProofRequest $request, Payment $payment): JsonResponse
    {
        $this->authorize('create', [PaymentProof::class, $payment]);

        $path = $request->file('file')->store('payment-proofs', 'public');

        $proof = $payment->proofs()->create([
            'file_path' => $path,
            'original_filename' => $request->file('file')->getClientOriginalName(),
            'mime_type' => $request->file('file')->getMimeType(),
            'file_size' => $request->file('file')->getSize(),
            'submitted_at' => now(),
            'status' => 'pending',
        ]);

        return $this->successResponse(
            new PaymentProofResource($proof),
            'Payment proof submitted successfully.',
            201
        );
    }

    public function update(UpdatePaymentProofRequest $request, PaymentProof $paymentProof): JsonResponse
    {
        $this->authorize('update', $paymentProof);

        $paymentProof->update([
            ...$request->validated(),
            'reviewed_at' => now(),
            'reviewed_by' => $request->user()->id,
        ]);

        if ($paymentProof->status === 'approved') {
            $paymentProof->payment->update([
                'status' => 'paid',
                'paid_at' => now(),
                'verified_at' => now(),
                'verified_by' => $request->user()->id,
            ]);
        }

        return $this->successResponse(
            new PaymentProofResource($paymentProof),
            'Payment proof updated successfully.'
        );
    }
}
