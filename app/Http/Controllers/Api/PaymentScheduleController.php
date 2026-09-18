<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ApiResponseTrait;
use App\Http\Controllers\Controller;
use App\Http\Requests\Payment\StorePaymentScheduleRequest;
use App\Http\Requests\Payment\UpdatePaymentScheduleRequest;
use App\Http\Resources\NotificationResource;
use App\Http\Resources\PaymentScheduleResource;
use App\Models\ClassModel;
use App\Models\PaymentSchedule;
use App\Models\SponsorStudent;
use App\Models\User;
use App\Services\Reminder\PaymentReminderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class PaymentScheduleController extends Controller
{
    use ApiResponseTrait;

    public function index(ClassModel $class): AnonymousResourceCollection
    {
        $this->authorize('view', $class);

        return PaymentScheduleResource::collection(
            $class->paymentSchedules()->with('classParticipant.user')->paginate()
        );
    }

    public function store(StorePaymentScheduleRequest $request, ClassModel $class): PaymentScheduleResource
    {
        $this->authorize('create', [PaymentSchedule::class, $class]);

        $schedule = $class->paymentSchedules()->create([
            ...$request->validated(),
            'generated_at' => now(),
        ]);

        return new PaymentScheduleResource($schedule);
    }

    public function show(PaymentSchedule $paymentSchedule): PaymentScheduleResource
    {
        $this->authorize('view', $paymentSchedule);

        return new PaymentScheduleResource($paymentSchedule->load(['classModel.paymentSetting', 'classParticipant.user', 'payments']));
    }

    public function update(UpdatePaymentScheduleRequest $request, PaymentSchedule $paymentSchedule): PaymentScheduleResource
    {
        $this->authorize('update', $paymentSchedule);

        $paymentSchedule->update($request->validated());

        return new PaymentScheduleResource($paymentSchedule);
    }

    public function destroy(PaymentSchedule $paymentSchedule): Response
    {
        $this->authorize('delete', $paymentSchedule);

        $paymentSchedule->delete();

        return response()->noContent();
    }

    /**
     * List the authenticated user's own payment schedules, across every class they participate in.
     */
    public function myPaymentSchedules(Request $request): JsonResponse
    {
        $perPage = min((int) $request->integer('per_page', 20), 100) ?: 20;
        $visibleUserIds = $this->visibleParticipantUserIds($request->user());

        $schedules = PaymentSchedule::query()
            ->whereHas('classParticipant', fn ($query) => $query->whereIn('user_id', $visibleUserIds))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->filled('class_id'), fn ($query) => $query->where('class_id', $request->integer('class_id')))
            ->with(['classModel.paymentSetting', 'payments'])
            ->orderBy('due_date')
            ->paginate($perPage);

        return $this->successResponse([
            'payment_schedules' => PaymentScheduleResource::collection($schedules)->resolve(),
            'pagination' => [
                'current_page' => $schedules->currentPage(),
                'per_page' => $schedules->perPage(),
                'total' => $schedules->total(),
                'last_page' => $schedules->lastPage(),
            ],
        ], 'Payment schedules retrieved successfully.');
    }

    /**
     * Return the authenticated user's single most relevant unpaid/pending schedule.
     */
    public function myCurrentPaymentSchedule(Request $request): JsonResponse
    {
        $visibleUserIds = $this->visibleParticipantUserIds($request->user());

        $schedule = PaymentSchedule::query()
            ->whereHas('classParticipant', fn ($query) => $query->whereIn('user_id', $visibleUserIds))
            ->whereIn('status', ['upcoming', 'pending', 'partially_paid', 'overdue'])
            ->with(['classModel.paymentSetting', 'payments'])
            ->orderBy('due_date')
            ->first();

        if (! $schedule) {
            return $this->successResponse(null, 'No current payment schedule found.');
        }

        return $this->successResponse(
            ['payment_schedule' => new PaymentScheduleResource($schedule)],
            'Current payment schedule retrieved successfully.'
        );
    }

    /**
     * Admin trigger for sending a manual reminder for a specific payment schedule.
     */
    public function sendReminder(Request $request, PaymentSchedule $paymentSchedule, PaymentReminderService $reminderService): JsonResponse
    {
        $this->authorize('sendReminder', $paymentSchedule);

        try {
            $notification = $reminderService->sendManualReminder($paymentSchedule, $request->user());
        } catch (\RuntimeException $exception) {
            return $this->errorResponse($exception->getMessage(), [], 422);
        }

        return $this->successResponse([
            'notification' => new NotificationResource($notification),
        ], 'Payment reminder sent successfully.');
    }

    /**
     * Admin preview of eligible payment reminders for an as-of date.
     */
    public function previewReminders(Request $request, PaymentReminderService $reminderService): JsonResponse
    {
        if (! $request->user()->hasPermission('notification.view') && ! $request->user()->hasPermission('payment.view')) {
            return $this->errorResponse('You are not authorized to preview reminders.', [], 403);
        }

        $preview = $reminderService->previewEligibleReminders($request->date('as_of_date'));

        return $this->successResponse($preview, 'Eligible reminders previewed successfully.');
    }

    /**
     * The user themselves, plus any student they actively sponsor.
     *
     * @return array<int, int>
     */
    private function visibleParticipantUserIds(User $user): array
    {
        $sponsoredStudentIds = SponsorStudent::query()
            ->where('sponsor_id', $user->id)
            ->where('status', 'active')
            ->pluck('student_id');

        return [$user->id, ...$sponsoredStudentIds->all()];
    }
}
