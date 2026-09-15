<?php

namespace App\Http\Resources;

use App\Models\ClassModel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ClassModel */
class ClassResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'organization_id' => $this->organization_id,
            'name' => $this->name,
            'description' => $this->description,
            'teacher_name' => $this->teacher_name,
            'day_of_week' => $this->day_of_week,
            'start_time' => $this->start_time,
            'frequency' => $this->frequency,
            'payment_amount' => $this->payment_amount,
            'status' => $this->status,
            'start_date' => $this->start_date,
            'end_date' => $this->end_date,
            'created_by' => $this->created_by,
            'schedules' => ClassScheduleResource::collection($this->whenLoaded('schedules')),
            'payment_setting' => new ClassPaymentSettingResource($this->whenLoaded('paymentSetting')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
