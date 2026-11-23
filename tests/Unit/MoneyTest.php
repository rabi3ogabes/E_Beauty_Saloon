<?php

use App\Support\Money\Money;

it('stores money as integer minor units and converts decimals exactly', function () {
    expect(Money::fromDecimal('149.50', 'QAR')->amount)->toBe(14950)
        ->and(Money::fromDecimal(0.1 + 0.2, 'QAR')->amount)->toBe(30)
        ->and(Money::of(14950)->toDecimal())->toBe('149.50')
        ->and(Money::fromDecimal('1.234', 'KWD')->amount)->toBe(1234);
});

it('allocates without losing a dirham', function () {
    $parts = Money::of(1000)->allocate(3);
    expect(array_map(fn ($m) => $m->amount, $parts))->toBe([334, 333, 333]);
});

it('refuses to mix currencies', function () {
    Money::of(100, 'QAR')->plus(Money::of(100, 'USD'));
})->throws(InvalidArgumentException::class);

it('formats for English and Arabic', function () {
    expect(Money::of(14950)->format('en'))->toBe('QAR 149.50')
        ->and(Money::of(14950)->format('ar'))->toBe('149.50 ر.ق');
});

it('sanitises CMS html (no javascript: links or event handlers)', function () {
    $out = safe_html('<p onclick="x()">Hi <a href="javascript:alert(1)" onmouseover="y()">bad</a> <a href="https://ok.example">ok</a><script>z()</script></p>');
    expect($out)->not->toContain('javascript:')->not->toContain('onclick')->not->toContain('onmouseover')->not->toContain('<script')
        ->toContain('href="https://ok.example"');
});
