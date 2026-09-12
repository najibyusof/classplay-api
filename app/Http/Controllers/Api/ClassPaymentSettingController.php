<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ApiResponseTrait;
use App\Http\Controllers\Controller;
use App\Http\Requests\ClassManagement\StoreClassPaymentSettingQrCodeRequest;
use App\Http\Requests\ClassManagement\StoreClassPaymentSettingRequest;
use App\Http\Requests\ClassManagement\UpdateClassPaymentSettingRequest;
use App\Http\Resources\ClassPaymentSettingResource;
use App\Models\ClassModel;
use App\Models\ClassPaymentSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;

class ClassPaymentSettingController extends Controller
{
    use ApiResponseTrait;

    public function show(ClassModel $class): JsonResponse
    {
        $this->authorize('view', $class);

        return $this->successResponse(
            new ClassPaymentSettingResource($class->paymentSetting()->firstOrFail()),
            'Class payment setting retrieved successfully.'
        );
    }

    public function store(StoreClassPaymentSettingRequest $request, ClassModel $class): JsonResponse
    {
        $this->authorize('create', [ClassPaymentSetting::class, $class]);

        $setting = $class->paymentSetting()->create($request->validated());

        return $this->successResponse(
            new ClassPaymentSettingResource($setting),
            'Class payment setting created successfully.',
            201
        );
    }

    public function update(UpdateClassPaymentSettingRequest $request, ClassModel $class): JsonResponse
    {
        $this->authorize('update', $class);

        $setting = $class->paymentSetting()->firstOrFail();
        $setting->update($request->validated());

        return $this->successResponse(
            new ClassPaymentSettingResource($setting),
            'Class payment setting updated successfully.'
        );
    }

    public function destroy(ClassModel $class): JsonResponse
    {
        $this->authorize('delete', $class);

        $class->paymentSetting()->firstOrFail()->delete();

        return $this->successResponse(null, 'Class payment setting deleted successfully.');
    }

    public function uploadQrCode(StoreClassPaymentSettingQrCodeRequest $request, ClassModel $class): JsonResponse
    {
        $this->authorize('update', $class);

        $setting = $class->paymentSetting()->firstOrFail();

        if ($setting->qr_code_path) {
            Storage::disk('public')->delete($setting->qr_code_path);
        }

        $path = $request->file('qr_code')->store('qr-codes', 'public');

        $setting->update(['qr_code_path' => $path]);

        return $this->successResponse(
            new ClassPaymentSettingResource($setting),
            'QR code uploaded successfully.'
        );
    }
}
