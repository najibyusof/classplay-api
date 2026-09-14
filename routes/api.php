<?php

use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\ClassController;
use App\Http\Controllers\Api\ClassParticipantController;
use App\Http\Controllers\Api\ClassPaymentSettingController;
use App\Http\Controllers\Api\ClassScheduleController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\NotificationLogController;
use App\Http\Controllers\Api\NotificationTemplateController;
use App\Http\Controllers\Api\OrganizationAdminController;
use App\Http\Controllers\Api\OrganizationController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\PaymentProofController;
use App\Http\Controllers\Api\PaymentReportController;
use App\Http\Controllers\Api\PaymentScheduleController;
use App\Http\Controllers\Api\PaymentTransactionController;
use App\Http\Controllers\Api\PaymentWebhookController;
use App\Http\Controllers\Api\PermissionController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\SponsorController;
use App\Http\Controllers\Api\SponsorStudentController;
use App\Http\Controllers\Api\StudentController;
use App\Http\Controllers\Api\UserDeviceController;
use App\Http\Controllers\Api\V1\Auth\AuthController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('v1.')->group(function () {
    Route::get('health', [HealthController::class, 'check'])->name('health');

    Route::prefix('auth')->name('auth.')->group(function () {
        Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login')->name('login');
        Route::post('/register/{userType}', [AuthController::class, 'register'])
            ->whereIn('userType', ['admin', 'student', 'sponsor'])
            ->middleware('throttle:registration')
            ->name('register');

        Route::middleware('auth:sanctum')->group(function () {
            Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
            Route::get('/me', [AuthController::class, 'me'])->name('me');
            Route::post('/change-password', [AuthController::class, 'changePassword'])->middleware('throttle:6,1')->name('change-password');
            Route::post('/set-password', [AuthController::class, 'setPassword'])->middleware('throttle:6,1')->name('set-password');
            Route::post('/refresh-token', [AuthController::class, 'refreshToken'])->name('refresh-token');
        });
    });

    Route::post('webhooks/payments/{gateway}', [PaymentWebhookController::class, 'handle'])->name('webhooks.payments');
    Route::post('payment/webhook/{provider}', [PaymentWebhookController::class, 'handle'])->name('payment.webhook');

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('admin/dashboard', [DashboardController::class, 'index'])->name('admin.dashboard');
        Route::get('admin/organizations/{organization}/dashboard', [DashboardController::class, 'organizationDashboard'])->name('admin.organizations.dashboard');

        Route::get('admin/payments', [PaymentReportController::class, 'index'])->name('admin.payments.index');
        Route::get('admin/reports/payment-summary', [PaymentReportController::class, 'summary'])->name('admin.reports.payment-summary');
        Route::get('admin/reports/outstanding', [PaymentReportController::class, 'outstanding'])->name('admin.reports.outstanding');
        Route::get('admin/reports/overdue', [PaymentReportController::class, 'overdue'])->name('admin.reports.overdue');

        Route::prefix('admin/organizations')->name('admin.organizations.')->group(function () {
            Route::get('/', [OrganizationController::class, 'index'])->name('index');
            Route::post('/', [OrganizationController::class, 'store'])->name('store');
            Route::get('/{organization}', [OrganizationController::class, 'show'])->name('show');
            Route::match(['put', 'patch'], '/{organization}', [OrganizationController::class, 'update'])->name('update');
            Route::delete('/{organization}', [OrganizationController::class, 'destroy'])->name('destroy');

            Route::get('/{organization}/admins', [OrganizationAdminController::class, 'index'])->name('admins.index');
            Route::post('/{organization}/admins', [OrganizationAdminController::class, 'store'])->name('admins.store');
            Route::match(['put', 'patch'], '/{organization}/admins/{user}', [OrganizationAdminController::class, 'update'])->name('admins.update');
            Route::delete('/{organization}/admins/{user}', [OrganizationAdminController::class, 'destroy'])->name('admins.destroy');
        });

        Route::prefix('admin/students')->name('admin.students.')->group(function () {
            Route::get('/', [StudentController::class, 'index'])->name('index');
            Route::post('/', [StudentController::class, 'store'])->name('store');
            Route::get('/{student}', [StudentController::class, 'show'])->name('show');
            Route::match(['put', 'patch'], '/{student}', [StudentController::class, 'update'])->name('update');
            Route::delete('/{student}', [StudentController::class, 'destroy'])->name('destroy');
        });

        Route::prefix('admin/sponsors')->name('admin.sponsors.')->group(function () {
            Route::get('/', [SponsorController::class, 'index'])->name('index');
            Route::post('/', [SponsorController::class, 'store'])->name('store');
            Route::get('/{sponsor}', [SponsorController::class, 'show'])->name('show');
            Route::match(['put', 'patch'], '/{sponsor}', [SponsorController::class, 'update'])->name('update');
            Route::delete('/{sponsor}', [SponsorController::class, 'destroy'])->name('destroy');

            Route::get('/{sponsor}/students', [SponsorStudentController::class, 'index'])->name('students.index');
            Route::post('/{sponsor}/students', [SponsorStudentController::class, 'store'])->name('students.store');
            Route::delete('/{sponsor}/students/{student}', [SponsorStudentController::class, 'destroy'])->name('students.destroy');
        });

        Route::prefix('admin/classes/{class}/participants')->name('admin.classes.participants.')->group(function () {
            Route::get('/', [ClassParticipantController::class, 'index'])->name('index');
            Route::post('/', [ClassParticipantController::class, 'store'])->name('store');
            Route::match(['put', 'patch'], '/{participant}', [ClassParticipantController::class, 'update'])->name('update');
            Route::delete('/{participant}', [ClassParticipantController::class, 'destroy'])->name('destroy');
        });

        Route::get('organizations/{organization}/classes', [ClassController::class, 'index'])->name('organizations.classes.index');
        Route::post('organizations/{organization}/classes', [ClassController::class, 'store'])->name('organizations.classes.store');
        Route::get('classes/{class}', [ClassController::class, 'show'])->name('classes.show');
        Route::match(['put', 'patch'], 'classes/{class}', [ClassController::class, 'update'])->name('classes.update');
        Route::delete('classes/{class}', [ClassController::class, 'destroy'])->name('classes.destroy');
        Route::post('admin/classes/{class}/activate', [ClassController::class, 'activate'])->name('admin.classes.activate');

        Route::get('classes/{class}/schedules', [ClassScheduleController::class, 'index'])->name('classes.schedules.index');
        Route::post('classes/{class}/schedules', [ClassScheduleController::class, 'store'])->name('classes.schedules.store');
        Route::match(['put', 'patch'], 'class-schedules/{classSchedule}', [ClassScheduleController::class, 'update'])->name('class-schedules.update');
        Route::delete('class-schedules/{classSchedule}', [ClassScheduleController::class, 'destroy'])->name('class-schedules.destroy');

        Route::get('classes/{class}/payment-setting', [ClassPaymentSettingController::class, 'show'])->name('classes.payment-setting.show');
        Route::post('classes/{class}/payment-setting', [ClassPaymentSettingController::class, 'store'])->name('classes.payment-setting.store');
        Route::match(['put', 'patch'], 'classes/{class}/payment-setting', [ClassPaymentSettingController::class, 'update'])->name('classes.payment-setting.update');
        Route::delete('classes/{class}/payment-setting', [ClassPaymentSettingController::class, 'destroy'])->name('classes.payment-setting.destroy');
        Route::post('classes/{class}/payment-setting/qr-code', [ClassPaymentSettingController::class, 'uploadQrCode'])->name('classes.payment-setting.qr-code');

        Route::get('payment-schedules/reminders/preview', [PaymentScheduleController::class, 'previewReminders'])->name('payment-schedules.reminders.preview');
        Route::get('classes/{class}/payment-schedules', [PaymentScheduleController::class, 'index'])->name('classes.payment-schedules.index');
        Route::post('classes/{class}/payment-schedules', [PaymentScheduleController::class, 'store'])->name('classes.payment-schedules.store');
        Route::get('payment-schedules/{paymentSchedule}', [PaymentScheduleController::class, 'show'])->name('payment-schedules.show');
        Route::match(['put', 'patch'], 'payment-schedules/{paymentSchedule}', [PaymentScheduleController::class, 'update'])->name('payment-schedules.update');
        Route::delete('payment-schedules/{paymentSchedule}', [PaymentScheduleController::class, 'destroy'])->name('payment-schedules.destroy');
        Route::post('payment-schedules/{paymentSchedule}/reminder', [PaymentScheduleController::class, 'sendReminder'])->name('payment-schedules.reminder');

        Route::get('payment-schedules/{paymentSchedule}/payments', [PaymentController::class, 'index'])->name('payment-schedules.payments.index');
        Route::post('payment-schedules/{paymentSchedule}/payments', [PaymentController::class, 'store'])->name('payment-schedules.payments.store');
        Route::get('payments/{payment}', [PaymentController::class, 'show'])->name('payments.show');
        Route::match(['put', 'patch'], 'payments/{payment}', [PaymentController::class, 'update'])->name('payments.update');
        Route::delete('payments/{payment}', [PaymentController::class, 'destroy'])->name('payments.destroy');

        Route::get('payments/{payment}/transactions', [PaymentTransactionController::class, 'index'])->name('payments.transactions.index');
        Route::post('payments/{payment}/transactions', [PaymentTransactionController::class, 'store'])->name('payments.transactions.store');

        Route::get('payments/{payment}/proofs', [PaymentProofController::class, 'index'])->name('payments.proofs.index');
        Route::post('payments/{payment}/proofs', [PaymentProofController::class, 'store'])->name('payments.proofs.store');
        Route::match(['put', 'patch'], 'payment-proofs/{paymentProof}', [PaymentProofController::class, 'update'])->name('payment-proofs.update');

        foreach (['student', 'sponsor'] as $participantType) {
            Route::prefix($participantType)->name($participantType.'.')->group(function () {
                Route::get('payment-schedules/current', [PaymentScheduleController::class, 'myCurrentPaymentSchedule'])->name('payment-schedules.current');
                Route::get('payment-schedules', [PaymentScheduleController::class, 'myPaymentSchedules'])->name('payment-schedules.index');
                Route::get('payment-schedules/{paymentSchedule}', [PaymentScheduleController::class, 'show'])->name('payment-schedules.show');
                Route::post('payment-schedules/{paymentSchedule}/payments', [PaymentController::class, 'store'])->name('payment-schedules.payments.store');

                Route::get('payments', [PaymentController::class, 'myPayments'])->name('payments.index');
                Route::get('payments/{payment}', [PaymentController::class, 'show'])->name('payments.show');
                Route::post('payments/{payment}/initiate', [PaymentController::class, 'initiate'])->name('payments.initiate');
            });
        }

        Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
        Route::get('notifications/unread-count', [NotificationController::class, 'unreadCount'])->name('notifications.unread-count');
        Route::post('notifications/read-all', [NotificationController::class, 'markAllAsRead'])->name('notifications.read-all');
        Route::get('notifications/{notification}', [NotificationController::class, 'show'])->name('notifications.show');
        Route::post('notifications/{notification}/read', [NotificationController::class, 'markAsRead'])->name('notifications.mark-as-read');
        Route::post('notifications/{notification}/unread', [NotificationController::class, 'markAsUnread'])->name('notifications.mark-as-unread');
        Route::match(['put', 'patch'], 'notifications/{notification}', [NotificationController::class, 'update'])->name('notifications.update');
        Route::delete('notifications/{notification}', [NotificationController::class, 'destroy'])->name('notifications.destroy');
        Route::get('notifications/{notification}/logs', [NotificationLogController::class, 'index'])->name('notifications.logs.index');

        Route::apiResource('devices', UserDeviceController::class);
        Route::apiResource('user-devices', UserDeviceController::class)->except(['show']);
        Route::apiResource('notification-templates', NotificationTemplateController::class);

        Route::get('audit-logs', [AuditLogController::class, 'index'])->name('audit-logs.index');
        Route::get('audit-logs/{auditLog}', [AuditLogController::class, 'show'])->name('audit-logs.show');

        Route::apiResource('roles', RoleController::class);
        Route::apiResource('permissions', PermissionController::class);
    });
});
