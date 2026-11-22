import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:luxe_salon/app/providers.dart';
import 'package:luxe_salon/core/api/api_exception.dart';
import 'package:luxe_salon/data/mock/mock_data.dart';
import 'package:luxe_salon/data/models/account.dart';
import 'package:luxe_salon/data/repositories/account_repository.dart';
import 'package:luxe_salon/features/auth/otp_screen.dart';
import 'package:material_ui/material_ui.dart';

import '../helpers.dart';

class FakeAuthRepository implements AuthRepository {
  final verifyCalls = <({String phone, String code})>[];
  int otpRequests = 0;
  bool reject = false;

  @override
  Future<void> requestOtp(String phone) async => otpRequests++;

  @override
  Future<AuthResult> verify({required String phone, required String code, String? name, required String deviceName}) async {
    verifyCalls.add((phone: phone, code: code));
    if (reject) {
      throw const ApiException(
        kind: ApiErrorKind.validation,
        statusCode: 422,
        message: 'Invalid code',
        errors: {
          'code': ['The code is invalid or has expired.'],
        },
      );
    }
    return AuthResult(token: 'tok-123', client: MockData.client);
  }

  @override
  Future<AuthResult> signInWithPassword({required String email, required String password, String? code, required String deviceName}) async =>
      const AuthResult(token: 'tok-team');

  @override
  Future<void> logout() async {}
}

void main() {
  setUpAll(disableGoogleFonts);

  late FakeAuthRepository auth;
  setUp(() => auth = FakeAuthRepository());

  Future<ProviderContainer> pump(WidgetTester tester, {VoidCallback? onVerified, Locale locale = const Locale('en')}) async {
    await tester.pumpWidget(
      testApp(
        OtpScreen(phone: '+97455501234', onVerified: onVerified),
        overrides: [authRepositoryProvider.overrideWithValue(auth)],
        locale: locale,
      ),
    );
    await tester.pump();
    return ProviderScope.containerOf(tester.element(find.byType(OtpScreen)));
  }

  testWidgets('shows the phone number and a disabled verify button', (tester) async {
    await pump(tester);
    expect(find.text('Check your messages'), findsOneWidget);
    expect(find.textContaining('+97455501234'), findsOneWidget);
    final button = tester.widget<FilledButton>(find.widgetWithText(FilledButton, 'Verify'));
    expect(button.onPressed, isNull);
    expect(find.textContaining('Resend in 30s'), findsOneWidget);
  });

  testWidgets('field supports one-time-code autofill and digits only', (tester) async {
    await pump(tester);
    final field = tester.widget<TextField>(find.byKey(const Key('otp-field')));
    expect(field.autofillHints, contains(AutofillHints.oneTimeCode));
    expect(field.keyboardType, TextInputType.number);

    await tester.enterText(find.byKey(const Key('otp-field')), '12a4');
    await tester.pump();
    expect(find.text('1'), findsOneWidget);
    expect(find.text('2'), findsOneWidget);
    expect(find.text('4'), findsOneWidget);
    expect(find.text('a'), findsNothing);
  });

  testWidgets('entering 6 digits auto-verifies and signs in', (tester) async {
    var verified = false;
    final container = await pump(tester, onVerified: () => verified = true);

    await tester.enterText(find.byKey(const Key('otp-field')), '123456');
    await tester.pumpAndSettle();

    expect(auth.verifyCalls.single, (phone: '+97455501234', code: '123456'));
    expect(container.read(sessionProvider).isSignedIn, isTrue);
    expect(container.read(sessionProvider).token, 'tok-123');
    expect(await container.read(tokenStoreProvider).read(), 'tok-123');
    expect(verified, isTrue);
  });

  testWidgets('a wrong code shows the server message and clears the input', (tester) async {
    auth.reject = true;
    final container = await pump(tester);

    await tester.enterText(find.byKey(const Key('otp-field')), '000000');
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('otp-error')), findsOneWidget);
    expect(find.text('The code is invalid or has expired.'), findsOneWidget);
    expect(container.read(sessionProvider).isSignedIn, isFalse);
    final field = tester.widget<TextField>(find.byKey(const Key('otp-field')));
    expect(field.controller!.text, isEmpty);
  });

  testWidgets('resend becomes available after the countdown', (tester) async {
    await pump(tester);
    await tester.pump(const Duration(seconds: 31));
    await tester.tap(find.text('Resend code'));
    await tester.pumpAndSettle();
    expect(auth.otpRequests, 1);
    expect(find.text('A new code is on its way.'), findsOneWidget);
  });

  testWidgets('renders right-to-left in Arabic with LTR code boxes', (tester) async {
    await pump(tester, locale: const Locale('ar'));
    expect(find.text('تحقّقي من رسائلك'), findsOneWidget);
    expect(Directionality.of(tester.element(find.text('تحقّقي من رسائلك'))), TextDirection.rtl);
    expect(Directionality.of(tester.element(find.byKey(const Key('otp-field')))), TextDirection.ltr);
  });
}
