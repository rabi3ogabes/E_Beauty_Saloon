/// Immutable money value in integer minor units — mirrors `App\Support\Money\Money`.
///
/// Never use doubles for money. The API sends `{amount, currency, formatted}`;
/// [format] re-formats locally so the app can switch language without a refetch.
class Money {
  const Money(this.amount, this.currency);

  factory Money.zero([String currency = 'QAR']) => Money(0, currency);

  factory Money.fromJson(Object? json, {String fallbackCurrency = 'QAR'}) {
    if (json is Map) {
      final raw = json['amount'];
      final amount = raw is int ? raw : (raw is num ? raw.round() : int.tryParse('$raw') ?? 0);
      return Money(amount, (json['currency'] as String?)?.toUpperCase() ?? fallbackCurrency);
    }
    if (json is int) return Money(json, fallbackCurrency);
    return Money(0, fallbackCurrency);
  }

  /// Minor units (dirhams for QAR, fils for KWD …).
  final int amount;
  final String currency;

  static const Map<String, int> _decimals = {'KWD': 3, 'BHD': 3, 'OMR': 3, 'JPY': 0};

  static const Map<String, String> _arabicSymbols = {
    'QAR': 'ر.ق',
    'SAR': 'ر.س',
    'AED': 'د.إ',
    'KWD': 'د.ك',
    'BHD': 'د.ب',
    'OMR': 'ر.ع',
  };

  static int decimalsFor(String currency) => _decimals[currency.toUpperCase()] ?? 2;

  int get decimals => decimalsFor(currency);

  bool get isZero => amount == 0;

  Map<String, Object> toJson() => {'amount': amount, 'currency': currency};

  Money operator +(Money other) {
    _assertSameCurrency(other);
    return Money(amount + other.amount, currency);
  }

  Money operator -(Money other) {
    _assertSameCurrency(other);
    return Money(amount - other.amount, currency);
  }

  Money times(int quantity) => Money(amount * quantity, currency);

  /// The decimal number with grouping, always Latin digits: `1,249.50`.
  String formatNumber() {
    final d = decimals;
    final negative = amount < 0;
    final abs = amount.abs();
    var divisor = 1;
    for (var i = 0; i < d; i++) {
      divisor *= 10;
    }
    final whole = abs ~/ divisor;
    final fraction = abs % divisor;

    final digits = whole.toString();
    final grouped = StringBuffer();
    for (var i = 0; i < digits.length; i++) {
      if (i > 0 && (digits.length - i) % 3 == 0) grouped.write(',');
      grouped.write(digits[i]);
    }
    final fractionText = d == 0 ? '' : '.${fraction.toString().padLeft(d, '0')}';

    return '${negative ? '-' : ''}$grouped$fractionText';
  }

  /// `QAR 149.50` in English, `149.50 ر.ق` in Arabic (same rule as the backend).
  String format([String locale = 'en']) {
    final number = formatNumber();
    if (locale.startsWith('ar')) {
      return '$number ${_arabicSymbols[currency] ?? currency}';
    }
    return '$currency $number';
  }

  void _assertSameCurrency(Money other) {
    if (other.currency != currency) {
      throw ArgumentError('Currency mismatch: $currency vs ${other.currency}');
    }
  }

  @override
  bool operator ==(Object other) => other is Money && other.amount == amount && other.currency == currency;

  @override
  int get hashCode => Object.hash(amount, currency);

  @override
  String toString() => format();
}
