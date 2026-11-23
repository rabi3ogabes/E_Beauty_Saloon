<?php

namespace App\Domain\CRM\Support;

use App\Domain\Booking\Models\Booking;
use App\Domain\CRM\Models\Client;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Client profile timeline: bookings (respecting branch scope), wallet, loyalty, notes and photos,
 * newest first. Private notes are only included for users with clients.private_notes.
 */
class ClientTimeline
{
    /** @return Collection<int, array{at:CarbonInterface, type:string, icon:string, color:string, title:string, body:?string, photo:?string}> */
    public static function for(Client $client, int $limit = 60): Collection
    {
        $canPrivate = (bool) auth()->user()?->can('clients.private_notes');
        $events = collect();

        Booking::query()->where('client_id', $client->id)->with('branch')->latest('starts_at')->limit($limit)->get()
            ->each(fn (Booking $b) => $events->push([
                'at' => $b->starts_at, 'type' => 'booking', 'icon' => 'heroicon-o-calendar-days', 'color' => 'primary',
                'title' => __('workspace.timeline.booking', ['ref' => $b->reference, 'status' => $b->status instanceof \BackedEnum ? $b->status->getLabel() : $b->status]),
                'body' => trim(($b->branch?->name ?? '').' · '.money((int) $b->total, $b->currency), ' ·'), 'photo' => null,
            ]));

        $client->walletTransactions()->limit($limit)->get()->each(fn ($t) => $events->push([
            'at' => $t->created_at, 'type' => 'wallet', 'icon' => 'heroicon-o-wallet', 'color' => $t->amount >= 0 ? 'success' : 'gray',
            'title' => __('workspace.timeline.wallet_'.($t->amount >= 0 ? 'in' : 'out'), ['amount' => money(abs((int) $t->amount))]),
            'body' => $t->note, 'photo' => null,
        ]));

        $client->loyaltyEntries()->limit($limit)->get()->each(fn ($e) => $events->push([
            'at' => $e->created_at, 'type' => 'loyalty', 'icon' => 'heroicon-o-gift', 'color' => $e->points >= 0 ? 'warning' : 'gray',
            'title' => __('workspace.timeline.points', ['points' => ($e->points > 0 ? '+' : '').$e->points]),
            'body' => $e->note ?? __('workspace.timeline.points_type.'.$e->type), 'photo' => null,
        ]));

        $client->notesTimeline()->with('user')->when(! $canPrivate, fn ($q) => $q->where('is_private', false))->limit($limit)->get()
            ->each(fn ($n) => $events->push([
                'at' => $n->created_at, 'type' => 'note', 'icon' => $n->is_private ? 'heroicon-o-lock-closed' : 'heroicon-o-chat-bubble-left-ellipsis', 'color' => 'gray',
                'title' => __('workspace.timeline.note', ['by' => $n->user?->name ?? __('workspace.timeline.team')]),
                'body' => $n->body, 'photo' => null,
            ]));

        $client->photos()->limit($limit)->get()->each(fn ($p) => $events->push([
            'at' => $p->created_at, 'type' => 'photo', 'icon' => 'heroicon-o-photo', 'color' => 'info',
            'title' => __('workspace.timeline.photo', ['kind' => __('workspace.clients.photo_kinds.'.$p->kind)]),
            'body' => $p->caption, 'photo' => route('tenant.workspace.client-photo', $p),
        ]));

        return $events->filter(fn ($e) => $e['at'] !== null)->sortByDesc(fn ($e) => $e['at']->getTimestamp())->take($limit)->values();
    }
}
