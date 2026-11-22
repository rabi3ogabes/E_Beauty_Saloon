import 'package:intl/intl.dart';
import 'package:material_ui/material_ui.dart';

import '../core/api/api_exception.dart';
import '../core/money/money.dart';
import 'generated/app_localizations.dart';

export 'generated/app_localizations.dart';

extension L10nX on BuildContext {
  AppLocalizations get l10n => AppLocalizations.of(this);

  String get lang => Localizations.localeOf(this).languageCode;

  bool get isArabic => lang == 'ar';

  /// Money in the current UI language: `QAR 149.50` / `149.50 ر.ق`.
  String money(Money m) => m.format(lang);

  /// `4:30 PM` / `4:30 م`
  String time(DateTime d) => DateFormat.jm(lang).format(d);

  /// `Sat, 22 Nov`
  String shortDate(DateTime d) => DateFormat('EEE, d MMM', lang).format(d);

  /// `Saturday 22 November 2026`
  String longDate(DateTime d) => DateFormat.yMMMMEEEEd(lang).format(d);

  String weekdayShort(DateTime d) => DateFormat.E(lang).format(d);

  String duration(int minutes) {
    final h = minutes ~/ 60;
    final m = minutes % 60;
    if (h == 0) return l10n.durationMinutes(m);
    if (m == 0) return l10n.durationHours(h);
    return l10n.durationHoursMinutes(h, m);
  }

  /// User-facing message for any error thrown by repositories.
  String errorMessage(Object error) {
    if (error is ApiException) {
      return switch (error.kind) {
        ApiErrorKind.network => l10n.errorNetwork,
        ApiErrorKind.timeout => l10n.errorTimeout,
        ApiErrorKind.moduleDisabled => l10n.errorModuleDisabled,
        ApiErrorKind.unauthorized => l10n.errorUnauthorized,
        ApiErrorKind.rateLimited => l10n.errorRateLimited,
        ApiErrorKind.server => l10n.errorServer,
        // Server-provided messages are already localised via Accept-Language.
        _ => error.message,
      };
    }
    return l10n.errorGeneric;
  }

  void showSnack(String message) {
    ScaffoldMessenger.maybeOf(this)
      ?..hideCurrentSnackBar()
      ..showSnackBar(SnackBar(content: Text(message)));
  }
}
