<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Organization extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'code',
        'description',
        'logo_path',
        'status',
        'created_by',
    ];

    /**
     * @return Attribute<string|null, never>
     */
    protected function logoUrl(): Attribute
    {
        return Attribute::make(
            get: fn (): ?string => $this->logo_path ? route('v1.organizations.logo-file', $this) : null,
        );
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasMany<OrganizationAdmin, $this>
     */
    public function organizationAdmins(): HasMany
    {
        return $this->hasMany(OrganizationAdmin::class);
    }

    /**
     * @return HasMany<ClassModel, $this>
     */
    public function classes(): HasMany
    {
        return $this->hasMany(ClassModel::class);
    }
}
