<?php

namespace App\Filament\Dashboard\Resources\TeamMembers\Pages;

use App\Domain\Team\Actions\InviteTeamMember;
use App\Filament\Dashboard\Resources\TeamMembers\TeamMemberResource;
use App\Support\Exceptions\DomainException;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Icons\Heroicon;

class ManageTeamMembers extends ManageRecords
{
    protected static string $resource = TeamMemberResource::class;

    public function getSubheading(): ?string
    {
        return __('workspace.team.subheading');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('invite')->label(__('workspace.team.invite'))->icon(Heroicon::OutlinedUserPlus)
                ->visible(fn () => TeamMemberResource::canManage())
                ->modalHeading(__('workspace.team.invite_heading'))
                ->modalDescription(__('workspace.team.invite_body'))
                ->schema(TeamMemberResource::memberFields(invite: true))
                ->action(function (array $data) {
                    try {
                        $user = app(InviteTeamMember::class)->handle($data['name'], $data['email'], $data['role'], $data['branch_ids'] ?? [], $data['staff_id'] ?? null);
                        Notification::make()->success()->title(__('workspace.team.invite_sent', ['email' => $user->email]))->send();
                    } catch (DomainException $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();
                    }
                }),
        ];
    }
}
