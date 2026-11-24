<?php

use App\Support\Exceptions\DomainException;
use App\Support\Monitoring\ErrorAlertMail;
use App\Support\Monitoring\ErrorContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Mail::fake();
    Cache::flush();
    config(['monitoring.alert_to' => ['owner@example.com'], 'monitoring.environments' => ['testing'], 'monitoring.throttle_minutes' => 15, 'monitoring.max_per_hour' => 20]);
});

function boom(string $message = 'Kaboom'): RuntimeException
{
    return new RuntimeException($message); // same file + line every call → same fingerprint
}

it('e-mails the owner when an unexpected error is reported', function () {
    report(boom());

    Mail::assertSent(ErrorAlertMail::class, fn (ErrorAlertMail $m) => $m->hasTo('owner@example.com')
        && $m->context->message === 'Kaboom'
        && str_contains($m->context->location, 'tests/Feature/Monitoring/ErrorAlertTest.php'));
});

it('alerts on a real 500 from a web request, with the URL', function () {
    Route::get('/__boom', fn () => throw new LogicException('Broken page'))->middleware('web');

    $this->get('/__boom')->assertStatus(500);

    Mail::assertSent(ErrorAlertMail::class, fn (ErrorAlertMail $m) => str_contains($m->context->request['url'] ?? '', '/__boom'));
});

it('sends the same error once per window and counts the repeats in the next alert', function () {
    foreach (range(1, 4) as $_) {
        report(boom());
    }
    Mail::assertSentCount(1);

    $this->travel(16)->minutes();
    report(boom());

    Mail::assertSentCount(2);
    Mail::assertSent(ErrorAlertMail::class, fn (ErrorAlertMail $m) => $m->context->repeats === 3);
});

it('caps alerts per hour so an outage never floods the inbox', function () {
    config(['monitoring.max_per_hour' => 3]);
    // Five different errors (different lines → different fingerprints).
    report(new LogicException('a'));
    report(new InvalidArgumentException('b'));
    report(new OutOfRangeException('c'));
    report(new UnexpectedValueException('d'));
    report(new RangeException('e'));

    Mail::assertSentCount(3);
});

it('does not alert on business-rule messages or 404s', function () {
    report(new DomainException('Sorry, this time was just taken.'));
    $this->get('/definitely-not-a-page-'.uniqid())->assertNotFound();

    Mail::assertNothingSent();
});

it('stays quiet when no address is set or outside the alert environments', function () {
    config(['monitoring.alert_to' => []]);
    report(boom());
    config(['monitoring.alert_to' => ['owner@example.com'], 'monitoring.environments' => ['production']]);
    report(new LogicException('local only'));

    Mail::assertNothingSent();
});

it('never lets a broken mailer break the request', function () {
    Mail::shouldReceive('to')->andThrow(new RuntimeException('SMTP down'));

    report(boom());

    expect(true)->toBeTrue(); // reached: no exception escaped
});

it('renders a readable alert with the stack and context', function () {
    $html = (new ErrorAlertMail(ErrorContext::from(boom('Render me'), 2)))->render();

    expect($html)->toContain('Render me')->toContain('ErrorAlertTest.php')->toContain('2</strong> more times');
});

it('has a command to send a test alert', function () {
    $this->artisan('monitoring:test-alert')->assertSuccessful();
    Mail::assertSent(ErrorAlertMail::class);
});
