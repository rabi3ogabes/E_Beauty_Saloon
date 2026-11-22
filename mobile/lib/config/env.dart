/// Compile-time switches (`--dart-define=KEY=value`).
abstract final class Env {
  /// Use in-app mock data for endpoints the backend has not shipped yet
  /// (availability, bookings, shop, AI Studio, packages, staff, owner).
  /// Flip to `false` once those endpoints are live.
  static const bool mockPendingApis = bool.fromEnvironment('MOCK_PENDING_APIS', defaultValue: true);

  /// Run the whole app against mock data, no backend required (screenshots, demos, design QA).
  static const bool demoMode = bool.fromEnvironment('DEMO_MODE', defaultValue: false);

  /// Firebase options supplied at build time (optional). When absent the app
  /// tries the native default config (google-services.json / GoogleService-Info.plist)
  /// and silently disables push if none is present.
  static const String firebaseApiKey = String.fromEnvironment('FIREBASE_API_KEY');
  static const String firebaseAppId = String.fromEnvironment('FIREBASE_APP_ID');
  static const String firebaseSenderId = String.fromEnvironment('FIREBASE_SENDER_ID');
  static const String firebaseProjectId = String.fromEnvironment('FIREBASE_PROJECT_ID');
}
