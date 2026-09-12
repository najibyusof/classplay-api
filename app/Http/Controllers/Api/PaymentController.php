<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ApiResponseTrait;
use App\Http\Controllers\Controller;
use App\Http\Requests\Payment\StorePaymentRequest;
use App\Http\Requests\Payment\UpdatePaymentRequest;
use App\Http\Resources\PaymentResource;
use App\Models\Payment;
use App\Models\PaymentSchedule;
use App\Services\Payment\PaymentInitiationResult;
use App\Services\Payment\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use RuntimeException;

class PaymentController extends Controller
{
    use ApiResponseTrait;

    public function __construct(private readonly PaymentService $paymentService) {}

    public function index(PaymentSchedule $paymentSchedule): AnonymousResourceCollection
    {
        $this->authorize('view', $paymentSchedule);

        return PaymentResource::collection($paymentSchedule->payments()->with('payer')->paginate());
    }

    public function store(StorePaymentRequest $request, PaymentSchedule $paymentSchedule): JsonResponse
    {
        $this->authorize('create', [Payment::class, $paymentSchedule]);

        try {
            $result = $this->paymentService->create(
                $paymentSchedule,
                $request->user(),
                (float) ($request->validated('additional_infaq') ?? 0),
                $request->validated('payment_method'),
            );
        } catch (RuntimeException $exception) {
            return $this->errorResponse($exception->getMessage());
        }

        return $this->successResponse(
            $this->buildPaymentResponseData($result),
            $result->payment->status === 'failed' ? 'Payment gateway initiation failed.' : 'Payment initiated successfully.',
            201
        );
    }

    /**
     * Explicitly (re-)initiate the gateway for an existing merchant payment,
     * reusing an already-active transaction instead of creating a duplicate.
     */
    public function initiate(Payment $payment): JsonResponse
    {
        $this->authorize('view', $payment);

        try {
            $result = $this->paymentService->initiate($payment);
        } catch (RuntimeException $exception) {
            return $this->errorResponse($exception->getMessage());
        }

        return $this->successResponse(
            $this->buildPaymentResponseData($result),
            $result->payment->status === 'failed' ? 'Payment gateway initiation failed.' : 'Payment gateway initiated successfully.'
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPaymentResponseData(PaymentInitiationResult $result): array
    {
        $data = ['payment' => new PaymentResource($result->payment->load('payer'))];

        if ($result->gatewayName) {
            $data['gateway'] = [
                'name' => $result->gatewayName,
                'payment_url' => $result->paymentUrl,
                'transaction_reference' => $result->transactionReference,
            ];
        }

        if ($result->qrInfo) {
            $data['qr'] = $result->qrInfo;
        }

        return $data;
    }

    /**
     * List the authenticated user's own payments, across every class, with optional filters.
     */
    public function myPayments(Request $request): JsonResponse
    {
        $perPage = min((int) $request->integer('per_page', 20), 100) ?: 20;

        $payments = Payment::query()
            ->where('payer_id', $request->user()->id)
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->filled('payment_method'), fn ($query) => $query->where('payment_method', $request->string('payment_method')))
            ->when($request->filled('date_from'), fn ($query) => $query->whereDate('created_at', '>=', $request->date('date_from')))
            ->when($request->filled('date_to'), fn ($query) => $query->whereDate('created_at', '<=', $request->date('date_to')))
            ->when($request->filled('class_id'), function ($query) use ($request) {
                $query->whereHas('paymentSchedule', fn ($q) => $q->where('class_id', $request->integer('class_id')));
            })
            ->with('payer')
            ->latest()
            ->paginate($perPage);

        return $this->successResponse([
            'payments' => PaymentResource::collection($payments)->resolve(),
            'pagination' => [
                'current_page' => $payments->currentPage(),
                'per_page' => $payments->perPage(),
                'total' => $payments->total(),
                'last_page' => $payments->lastPage(),
            ],
        ], 'Payments retrieved successfully.');
    }

    public function show(Payment $payment): JsonResponse
    {
        $this->authorize('view', $payment);

        return $this->successResponse(
            ['payment' => new PaymentResource($payment->load('payer'))],
            'Payment retrieved successfully.'
        );
    }

    public function update(UpdatePaymentRequest $request, Payment $payment): JsonResponse
    {
        $this->authorize('update', $payment);

        $data = $request->validated();

        if (($data['status'] ?? null) === 'paid') {
            $data['paid_at'] = now();
            $data['verified_at'] = now();
            $data['verified_by'] = $request->user()->id;
        }

        $payment->update($data);

        return $this->successResponse(
            ['payment' => new PaymentResource($payment->load('payer'))],
            'Payment updated successfully.'
        );
    }

    public function destroy(Payment $payment): JsonResponse
    {
        $this->authorize('delete', $payment);

        $payment->delete();

        return $this->successResponse(null, 'Payment deleted successfully.');
    }
}
