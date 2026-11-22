import '../../core/money/money.dart';
import 'json.dart';

/// `GET /me` (ClientResource).
class ClientProfile {
  const ClientProfile({
    required this.id,
    required this.name,
    required this.phone,
    this.email,
    this.gender,
    this.birthday,
    this.locale,
    this.walletBalance = const Money(0, 'QAR'),
    this.loyaltyPoints = 0,
    this.loyaltyTier,
    this.referralCode,
    this.consents = const {},
  });

  factory ClientProfile.fromJson(Json j) {
    final loyalty = asJson(j['loyalty']);
    final consents = <String, bool>{};
    asJson(j['consents']).forEach((k, v) => consents[k] = asBool(v));
    return ClientProfile(
      id: asInt(j['id']),
      name: asString(j['name']),
      phone: asString(j['phone']),
      email: asStringOrNull(j['email']),
      gender: asStringOrNull(j['gender']),
      birthday: asStringOrNull(j['birthday']),
      locale: asStringOrNull(j['locale']),
      walletBalance: Money.fromJson(j['wallet_balance']),
      loyaltyPoints: asInt(loyalty['points']),
      loyaltyTier: asStringOrNull(loyalty['tier']),
      referralCode: asStringOrNull(j['referral_code']),
      consents: consents,
    );
  }

  final int id;
  final String name;
  final String phone;
  final String? email;
  final String? gender;
  final String? birthday;
  final String? locale;
  final Money walletBalance;
  final int loyaltyPoints;
  final String? loyaltyTier;
  final String? referralCode;

  /// whatsapp / sms / email / push => granted.
  final Map<String, bool> consents;

  static const consentChannels = ['whatsapp', 'sms', 'email', 'push'];

  String get firstName => name.trim().split(' ').first;

  ClientProfile copyWith({String? name, String? email, String? locale, Map<String, bool>? consents}) => ClientProfile(
    id: id,
    name: name ?? this.name,
    phone: phone,
    email: email ?? this.email,
    gender: gender,
    birthday: birthday,
    locale: locale ?? this.locale,
    walletBalance: walletBalance,
    loyaltyPoints: loyaltyPoints,
    loyaltyTier: loyaltyTier,
    referralCode: referralCode,
    consents: consents ?? this.consents,
  );
}

/// `POST /auth/verify`
class AuthResult {
  const AuthResult({required this.token, required this.client});

  factory AuthResult.fromJson(Json j) => AuthResult(token: asString(j['token']), client: ClientProfile.fromJson(asJson(j['client'])));

  final String token;
  final ClientProfile client;
}

class WalletTransaction {
  const WalletTransaction({
    required this.id,
    required this.type,
    required this.amount,
    required this.balanceAfter,
    this.note,
    this.createdAt,
  });

  factory WalletTransaction.fromJson(Json j) => WalletTransaction(
    id: asInt(j['id']),
    type: asString(j['type']),
    amount: Money.fromJson(j['amount']),
    balanceAfter: Money.fromJson(j['balance_after']),
    note: asStringOrNull(j['note']),
    createdAt: asDate(j['created_at']),
  );

  final int id;
  final String type;
  final Money amount;
  final Money balanceAfter;
  final String? note;
  final DateTime? createdAt;

  bool get isCredit => amount.amount >= 0;
}

/// `GET /me/wallet`
class Wallet {
  const Wallet({required this.balance, this.transactions = const []});

  factory Wallet.fromJson(Json j) => Wallet(
    balance: Money.fromJson(j['balance']),
    transactions: asJsonList(j['transactions']).map(WalletTransaction.fromJson).toList(),
  );

  final Money balance;
  final List<WalletTransaction> transactions;
}

class LoyaltyEntry {
  const LoyaltyEntry({
    required this.id,
    required this.type,
    required this.points,
    required this.balanceAfter,
    this.note,
    this.createdAt,
  });

  factory LoyaltyEntry.fromJson(Json j) => LoyaltyEntry(
    id: asInt(j['id']),
    type: asString(j['type']),
    points: asInt(j['points']),
    balanceAfter: asInt(j['balance_after']),
    note: asStringOrNull(j['note']),
    createdAt: asDate(j['created_at']),
  );

  final int id;
  final String type;
  final int points;
  final int balanceAfter;
  final String? note;
  final DateTime? createdAt;
}

/// `GET /me/loyalty`
class LoyaltySummary {
  const LoyaltySummary({required this.points, this.tier, this.referralCode, this.entries = const []});

  factory LoyaltySummary.fromJson(Json j) => LoyaltySummary(
    points: asInt(j['points']),
    tier: asStringOrNull(j['tier']),
    referralCode: asStringOrNull(j['referral_code']),
    entries: asJsonList(j['entries']).map(LoyaltyEntry.fromJson).toList(),
  );

  final int points;
  final String? tier;
  final String? referralCode;
  final List<LoyaltyEntry> entries;
}
