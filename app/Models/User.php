<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Auth\Notifications\ResetPassword as ResetPasswordNotification;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'phone', 'email', 'password', 'user_type', 'status', 'telegram_chat_id'])]
#[Hidden(['password'])]
class User extends Authenticatable implements CanResetPassword
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    /**
     * Keep the RBAC role assignment in sync with user_type, since user_type
     * alone must never be used as the authorization decision.
     */
    protected static function booted(): void
    {
        static::saved(function (User $user): void {
            if ($user->wasRecentlyCreated || $user->wasChanged('user_type')) {
                $user->syncRoleFromUserType();
            }
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'phone_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * @return BelongsToMany<Role, $this>
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_roles');
    }

    /**
     * @return HasMany<OrganizationAdmin, $this>
     */
    public function organizationAdmins(): HasMany
    {
        return $this->hasMany(OrganizationAdmin::class);
    }

    /**
     * @return HasMany<ClassParticipant, $this>
     */
    public function classParticipants(): HasMany
    {
        return $this->hasMany(ClassParticipant::class);
    }

    /**
     * @return HasMany<SponsorStudent, $this>
     */
    public function sponsoredStudents(): HasMany
    {
        return $this->hasMany(SponsorStudent::class, 'sponsor_id');
    }

    /**
     * @return HasMany<SponsorStudent, $this>
     */
    public function sponsors(): HasMany
    {
        return $this->hasMany(SponsorStudent::class, 'student_id');
    }

    /**
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'payer_id');
    }

    /**
     * @return HasMany<Payment, $this>
     */
    public function verifiedPayments(): HasMany
    {
        return $this->hasMany(Payment::class, 'verified_by');
    }

    /**
     * @return HasMany<Notification, $this>
     */
    public function appNotifications(): HasMany
    {
        return $this->hasMany(Notification::class);
    }

    /**
     * @return HasMany<UserDevice, $this>
     */
    public function devices(): HasMany
    {
        return $this->hasMany(UserDevice::class);
    }

    /**
     * @return HasMany<AuditLog, $this>
     */
    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }

    public function isAdminType(): bool
    {
        return $this->user_type === 'admin';
    }

    public function isOrganizationAdmin(int $organizationId): bool
    {
        return $this->organizationAdmins()
            ->where('organization_id', $organizationId)
            ->where('status', 'active')
            ->exists();
    }

    /**
     * Determine whether $target (a student or sponsor) participates in a class
     * belonging to an organization this user actively administers.
     */
    public function canManageParticipantUser(User $target): bool
    {
        $organizationIds = $this->organizationAdmins()->where('status', 'active')->pluck('organization_id');

        if ($organizationIds->isEmpty()) {
            return false;
        }

        return ClassParticipant::query()
            ->where('user_id', $target->id)
            ->whereHas('classModel', fn ($query) => $query->whereIn('organization_id', $organizationIds))
            ->exists();
    }

    /**
     * Determine whether this user is an active sponsor of the given student.
     */
    public function isSponsorOf(int $studentUserId): bool
    {
        return $this->sponsoredStudents()
            ->where('student_id', $studentUserId)
            ->where('status', 'active')
            ->exists();
    }

    /**
     * Determine whether the user holds any of the given RBAC roles (e.g. ADMIN, STUDENT, SPONSOR).
     */
    public function hasRole(string ...$roles): bool
    {
        $names = array_map('strtoupper', $roles);

        return $this->roles()->whereIn('name', $names)->exists();
    }

    /**
     * Determine whether any of the user's roles grant the given permission.
     */
    public function hasPermission(string $permission): bool
    {
        return $this->roles()
            ->whereHas('permissions', fn ($query) => $query->where('name', $permission))
            ->exists();
    }

    /**
     * Ensure the user holds the RBAC role matching their user_type classification.
     */
    public function syncRoleFromUserType(): void
    {
        $role = Role::query()->firstOrCreate(['name' => strtoupper($this->user_type)]);

        $this->roles()->syncWithoutDetaching([$role->id]);
    }

    public function getEmailForPasswordReset(): string
    {
        return (string) $this->email;
    }

    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ResetPasswordNotification($token));
    }
}
