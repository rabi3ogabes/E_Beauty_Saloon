import 'package:flutter_test/flutter_test.dart';
import 'package:luxe_salon/core/money/money.dart';

void main() {
  group('Money.format', () {
    test('minor units to English with currency prefix', () {
      expect(const Money(14950, 'QAR').format('en'), 'QAR 149.50');
    });

    test('Arabic puts the localised symbol after the number', () {
      expect(const Money(14950, 'QAR').format('ar'), '149.50 ر.ق');
    });

    test('groups thousands', () {
      expect(const Money(123456789, 'QAR').format('en'), 'QAR 1,234,567.89');
    });

    test('pads small amounts', () {
      expect(const Money(5, 'QAR').format('en'), 'QAR 0.05');
      expect(const Money(0, 'QAR').format('en'), 'QAR 0.00');
    });

    test('three-decimal currencies', () {
      expect(const Money(12345, 'KWD').format('en'), 'KWD 12.345');
      expect(const Money(12345, 'KWD').format('ar'), '12.345 د.ك');
    });

    test('zero-decimal currencies', () {
      expect(const Money(1500, 'JPY').format('en'), 'JPY 1,500');
    });

    test('negative amounts', () {
      expect(const Money(-30000, 'QAR').format('en'), 'QAR -300.00');
    });

    test('unknown currency falls back to its code in Arabic', () {
      expect(const Money(1000, 'EUR').format('ar'), '10.00 EUR');
    });
  });

  group('Money arithmetic & parsing', () {
    test('fromJson reads the API money object', () {
      final m = Money.fromJson({'amount': 14950, 'currency': 'qar', 'formatted': 'QAR 149.50'});
      expect(m, const Money(14950, 'QAR'));
    });

    test('fromJson tolerates bad input', () {
      expect(Money.fromJson(null).amount, 0);
      expect(Money.fromJson({'amount': '250'}).amount, 250);
    });

    test('add, subtract and multiply stay in minor units', () {
      const a = Money(14950, 'QAR');
      expect((a + const Money(50, 'QAR')).amount, 15000);
      expect((a - const Money(950, 'QAR')).amount, 14000);
      expect(a.times(3).amount, 44850);
    });

    test('currency mismatch throws', () {
      expect(() => const Money(1, 'QAR') + const Money(1, 'SAR'), throwsArgumentError);
    });
  });
}
