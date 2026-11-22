<?php

namespace App\Http\Resources\V1;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Staff / owner app profile. `permissions` lists the app-relevant abilities the member holds so the apps can
 * hide actions up front (the API enforces them regardless).
 *
 * @mixin User
 */
class TeamMemberResource extends JsonResource
{
    public const APP_PERMISSIONS = [
        'bookings.view', 'bookings.manage', 'bookings.own_only', 'clients.view', 'clients.private_notes',
        'reports.view', 'reports.revenue', 'marketing.send', 'staff.manage',
    ];

    public function toArray(Request $request): array
    {
        $staff = $this->staffProfile()->with('branches:id')->first();
        $branchIds = $this->restrictedBranchIds();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'locale' => $this->locale ?? null,
            'avatar_url' => $this->getFilamentAvatarUrl(),
            'is_owner' => $this->isOwner(),
            'roles' => $this->getRoleNames()->values(),
            'permissions' => collect(self::APP_PERMISSIONS)->filter(fn ($p) => $this->resource->can($p))->values(),
            'branch_ids' => $branchIds, // null = all branches
            'mfa_enabled' => (bool) $this->getAppAuthenticationSecret(),
            'staff' => $staff ? [
                'id' => $staff->id,
                'name' => $staff->name,
                'title' => $staff->title,
                'photo_url' => $staff->photo_path ? asset('storage/'.$staff->photo_path) : null,
                'branch_ids' => $staff->branches->pluck('id')->values(),
            ] : null,
            'salon' => ['name' => tenant()->name, 'slug' => tenant()->slug, 'timezone' => tenant()->timezone, 'currency' => tenant()->currency],
        ];
    }
}
