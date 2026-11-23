<?php

namespace App\Filament\Dashboard\Pages\Assistant;

use App\Domain\Assistant\Actions\ReplyAsStaff;
use App\Domain\Assistant\Models\Conversation;
use App\Domain\Assistant\Support\AssistantSettings;
use App\Domain\Insights\Support\InsightsGate;
use App\Support\Exceptions\DomainException;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;

/** WhatsApp inbox: AI receptionist conversations, handoffs, and staff replies. */
class AssistantInbox extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static ?string $slug = 'inbox';

    protected static ?int $navigationSort = 50;

    protected string $view = 'filament.dashboard.assistant.inbox';

    #[Url]
    public ?int $conversation = null;

    #[Url]
    public string $filter = 'all';

    public string $reply = '';

    public static function canAccess(): bool
    {
        return InsightsGate::inbox();
    }

    public static function getNavigationGroup(): ?string
    {
        return __('salon.nav.clients');
    }

    public static function getNavigationLabel(): string
    {
        return __('insights.assistant.inbox');
    }

    public static function getNavigationBadge(): ?string
    {
        $unread = tenant() ? (int) Conversation::query()->sum('unread_count') : 0;

        return $unread ? (string) $unread : null;
    }

    public function getTitle(): string
    {
        return __('insights.assistant.inbox');
    }

    public function getSubheading(): ?string
    {
        return AssistantSettings::get('enabled') ? __('insights.assistant.ai_on') : __('insights.assistant.ai_off');
    }

    #[Computed]
    public function conversations(): Collection
    {
        return Conversation::query()->with('client')
            ->when($this->filter === 'handoff', fn ($q) => $q->where('status', 'handoff'))
            ->when($this->filter === 'unread', fn ($q) => $q->where('unread_count', '>', 0))
            ->orderByDesc('last_message_at')->limit(60)->get();
    }

    #[Computed]
    public function current(): ?Conversation
    {
        return $this->conversation ? Conversation::with(['messages.user', 'client', 'lastBooking'])->find($this->conversation) : null;
    }

    public function open(int $id): void
    {
        $this->conversation = $id;
        $this->reply = '';
        if ($c = Conversation::find($id)) {
            app(ReplyAsStaff::class)->markRead($c);
        }
        unset($this->current, $this->conversations);
    }

    public function setFilter(string $filter): void
    {
        $this->filter = in_array($filter, ['all', 'handoff', 'unread'], true) ? $filter : 'all';
        unset($this->conversations);
    }

    public function send(): void
    {
        $this->validate(['reply' => 'required|string|max:4000']);
        $c = Conversation::findOrFail($this->conversation);
        try {
            app(ReplyAsStaff::class)->handle($c, $this->reply);
        } catch (DomainException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            return;
        }
        $this->reply = '';
        unset($this->current, $this->conversations);
    }

    public function resumeAi(): void
    {
        app(ReplyAsStaff::class)->resumeAi(Conversation::findOrFail($this->conversation));
        unset($this->current, $this->conversations);
        Notification::make()->success()->title(__('insights.assistant.resumed'))->send();
    }

    public function takeOver(): void
    {
        Conversation::findOrFail($this->conversation)->update(['status' => 'handoff', 'handoff_reason' => 'staff']);
        unset($this->current, $this->conversations);
    }
}
