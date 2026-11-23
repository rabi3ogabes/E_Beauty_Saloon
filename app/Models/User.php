<?php

namespace App\Models;

use App\Domain\Branches\Models\Branch;
use App\Domain\Team\Models\Staff;
use App\Domain\Tenancy\Models\Tenant;
use Database\Factories\UserFactory;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasAvatar;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements FilamentUser, HasAppAuthentication, HasAppAuthenticationRecovery, HasAvatar
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable;

    protected $guarded = ['id', 'is_platform'];

    protected $hidden = ['password', 'remember_token', 'pos_pin', 'app_authentication_secret', 'app_authentication_recovery_codes'];

    /** Tenant roles (created per tenant as spatie "team" roles). */
    public const TENANT_ROLES = ['owner', 'branch_manager', 'receptionist', 'stylist', 'accountant', 'marketing'];

    /** Platform (super admin side) roles. */
    public const PLATFORM_ROLES = ['platform_owner', 'sales', 'support', 'finance'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'is_platform' => 'boolean',
            'is_active' => 'boolean',
            'app_authentication_secret' => 'encrypted',
            'app_authentication_recovery_codes' => 'encrypted:array',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class)->withoutGlobalScopes();
    }

    public function staffProfile(): HasOne
    {
        return $this->hasOne(Staff::class)->withoutGlobalScopes();
    }

    public function canAccessPanel(Panel $panel): bool
    {
        if ($this->is_active === false) {
            return false;
        }

        return match ($panel->getId()) {
            'admin' => $this->is_platform,
            'dashboard' => ! $this->is_platform && $this->tenant_id !== null && $this->tenant_id === tenant()?->id,
            'pos' => ! $this->is_platform && $this->tenant_id !== null && $this->tenant_id === tenant()?->id
                && $this->hasAnyRole(['owner', 'branch_manager', 'receptionist']),
            default => false,
        };
    }

    public function isOwner(): bool
    {
        return $this->tenant?->owner_user_id === $this->id || $this->hasRole('owner');
    }

    /** Branch ids this user may see; null = all branches (owners, accountants, marketing). */
    public function restrictedBranchIds(): ?array
    {
        if ($this->is_platform || $this->isOwner() || $this->hasAnyRole(['accountant', 'marketing'])) {
            return null;
        }

        return $this->branches->pluck('id')->all();
    }

    public function setPosPin(string $pin): void
    {
        $this->forceFill(['pos_pin' => Hash::make($pin)])->save();
    }

    public function checkPosPin(string $pin): bool
    {
        return $this->pos_pin !== null && Hash::check($pin, $this->pos_pin);
    }

    public function getFilamentAvatarUrl(): ?string
    {
        return $this->avatar_path ? asset('storage/'.$this->avatar_path) : null;
    }

    public function getAppAuthenticationSecret(): ?string
    {
        return $this->app_authentication_secret;
    }

    public function saveAppAuthenticationSecret(?string $secret): void
    {
        $this->forceFill(['app_authentication_secret' => $secret])->save();
    }

    public function getAppAuthenticationHolderName(): string
    {
        return $this->email;
    }

    public function getAppAuthenticationRecoveryCodes(): ?array
    {
        return $this->app_authentication_recovery_codes;
    }

    public function saveAppAuthenticationRecoveryCodes(?array $codes): void
    {
        $this->forceFill(['app_authentication_recovery_codes' => $codes])->save();
    }
}
