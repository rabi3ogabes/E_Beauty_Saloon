<?php

namespace App\Filament\Dashboard\Resources\Marketing\Automations\Pages;

use App\Domain\Marketing\Automation\TriggerRegistry;
use App\Domain\Marketing\Models\Automation;
use App\Domain\Marketing\Models\WhatsappTemplate;
use App\Filament\Dashboard\Resources\Marketing\Automations\AutomationResource;
use App\Filament\Marketing\MarketingAccess;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;

/**
 * Visual flow builder: a vertical list of steps (trigger → wait → condition → send / points / tag / coupon),
 * edited client-side with Alpine and saved through Livewire.
 */
class EditAutomationFlow extends Page
{
    use InteractsWithRecord;

    protected static string $resource = AutomationResource::class;

    protected string $view = 'filament.marketing.automation-flow';

    public string $name = '';

    public string $trigger = '';

    public array $triggerConfig = [];

    public array $steps = [];

    public bool $isActive = false;

    public const STEP_TYPES = ['wait', 'condition', 'send_message', 'award_points', 'add_tag', 'coupon'];

    public static function canAccess(array $parameters = []): bool
    {
        return MarketingAccess::manage();
    }

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        /** @var Automation $automation */
        $automation = $this->record;
        $this->name = $automation->name;
        $this->trigger = $automation->trigger;
        $this->triggerConfig = array_replace(app(TriggerRegistry::class)->defaults($automation->trigger), $automation->trigger_config ?? []);
        $this->isActive = $automation->is_active;
        $this->steps = collect($automation->steps ?? [])->map(fn ($s) => $this->normalizeStep($s))->values()->all();
    }

    public function updatedTrigger(string $value): void
    {
        $this->triggerConfig = app(TriggerRegistry::class)->defaults($value);
    }

    public function getTitle(): string
    {
        return __('marketing.automations.builder').' · '.$this->name;
    }

    protected function normalizeStep(array $s): array
    {
        $body = $s['body'] ?? [];

        return array_merge([
            'type' => 'wait', 'days' => 0, 'hours' => 0, 'minutes' => 0, 'check' => 'has_consent', 'value' => '',
            'channels' => ['whatsapp', 'sms'], 'template' => '', 'link' => '', 'transactional' => false,
            'points' => 100, 'tag' => '', 'discount_bp' => 1000, 'valid_days' => 14,
        ], $s, ['body' => ['en' => is_array($body) ? ($body['en'] ?? '') : (string) $body, 'ar' => is_array($body) ? ($body['ar'] ?? '') : '']]);
    }

    /** Strip each step down to the keys its type uses. */
    protected function cleanStep(array $s): array
    {
        $type = in_array($s['type'] ?? null, self::STEP_TYPES, true) ? $s['type'] : 'wait';

        return match ($type) {
            'wait' => array_filter(['type' => 'wait', 'days' => (int) ($s['days'] ?? 0), 'hours' => (int) ($s['hours'] ?? 0), 'minutes' => (int) ($s['minutes'] ?? 0)], fn ($v) => $v !== 0),
            'condition' => ['type' => 'condition', 'check' => (string) ($s['check'] ?? 'has_consent'), 'value' => $s['value'] ?? null],
            'send_message' => array_filter([
                'type' => 'send_message',
                'channels' => array_values(array_intersect(['whatsapp', 'sms', 'email', 'push'], (array) ($s['channels'] ?? []))) ?: ['whatsapp'],
                'template' => $s['template'] ?: null, 'link' => $s['link'] ?: null, 'transactional' => (bool) ($s['transactional'] ?? false) ?: null,
                'body' => ['en' => trim((string) data_get($s, 'body.en')), 'ar' => trim((string) data_get($s, 'body.ar'))],
            ], fn ($v) => $v !== null),
            'award_points' => ['type' => 'award_points', 'points' => max(1, (int) ($s['points'] ?? 0))],
            'add_tag' => ['type' => 'add_tag', 'tag' => trim((string) ($s['tag'] ?? ''))],
            'coupon' => ['type' => 'coupon', 'discount_bp' => max(100, (int) ($s['discount_bp'] ?? 1000)), 'valid_days' => max(1, (int) ($s['valid_days'] ?? 14))],
        };
    }

    public function save(): void
    {
        abort_unless(MarketingAccess::manage(), 403);
        $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'trigger' => ['required', 'in:'.implode(',', app(TriggerRegistry::class)->keys())],
            'steps' => ['required', 'array', 'min:1', 'max:20'],
            'steps.*.type' => ['required', 'in:'.implode(',', self::STEP_TYPES)],
            'steps.*.body.en' => ['nullable', 'string', 'max:1000'],
            'steps.*.body.ar' => ['nullable', 'string', 'max:1000'],
        ]);
        foreach ($this->steps as $i => $step) {
            if (($step['type'] ?? null) === 'send_message' && blank(data_get($step, 'body.en')) && blank(data_get($step, 'body.ar'))) {
                $this->addError("steps.$i.body.en", __('validation.required', ['attribute' => __('marketing.automations.fields.body_en')]));

                return;
            }
        }

        $this->record->update([
            'name' => $this->name, 'trigger' => $this->trigger, 'is_active' => $this->isActive,
            'trigger_config' => collect($this->triggerConfig)->map(fn ($v) => is_numeric($v) ? $v + 0 : $v)->all(),
            'steps' => collect($this->steps)->map(fn ($s) => $this->cleanStep($s))->values()->all(),
        ]);
        activity()->performedOn($this->record)->log('automation.flow_saved');
        Notification::make()->success()->title(__('marketing.automations.flow_saved'))->send();
    }

    protected function getViewData(): array
    {
        return [
            'triggers' => app(TriggerRegistry::class)->options(),
            'stepTypes' => collect(self::STEP_TYPES)->mapWithKeys(fn ($t) => [$t => __('marketing.automations.step_types.'.$t)])->all(),
            'checks' => __('marketing.automations.checks'),
            'templates' => WhatsappTemplate::where('status', 'approved')->pluck('name')->unique()->values()->all(),
            'blank' => $this->normalizeStep(['type' => 'send_message']),
        ];
    }
}
