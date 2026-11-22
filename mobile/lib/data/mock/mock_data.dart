import '../../core/money/money.dart';
import '../models/account.dart';
import '../models/catalog.dart';
import '../models/commerce.dart';
import '../models/studio.dart';

/// Demo content for "Maison Lumière" — used by mock repositories and tests.
abstract final class MockData {
  static const currency = 'QAR';

  static Money qar(int minor) => Money(minor, currency);

  static const salon = Salon(
    name: 'Maison Lumière',
    slug: 'lumiere',
    currency: currency,
    timezone: 'Asia/Qatar',
    modules: ['booking', 'shop', 'ai_studio', 'loyalty', 'marketing'],
    branding: Branding(primary: '#C8A96A', accent: '#E9D6CF', ink: '#1C1A17', background: '#FAF7F2'),
  );

  static const branches = [
    Branch(id: 1, slug: 'pearl', name: 'The Pearl', area: 'Porto Arabia', city: 'Doha', isOpenNow: true, whatsapp: '97444000001'),
    Branch(id: 2, slug: 'west-bay', name: 'West Bay', area: 'Diplomatic Area', city: 'Doha', isOpenNow: true, whatsapp: '97444000002'),
    Branch(id: 3, slug: 'lusail', name: 'Lusail Marina', area: 'Marina District', city: 'Lusail', isOpenNow: false),
  ];

  static const categories = [
    ServiceCategory(id: 1, slug: 'hair', name: 'Hair'),
    ServiceCategory(id: 2, slug: 'color', name: 'Colour'),
    ServiceCategory(id: 3, slug: 'nails', name: 'Nails'),
    ServiceCategory(id: 4, slug: 'face', name: 'Facials'),
    ServiceCategory(id: 5, slug: 'makeup', name: 'Makeup'),
  ];

  static final services = <SalonService>[
    SalonService(
      id: 101,
      categoryId: 1,
      name: 'Signature Blow-dry',
      description: 'Wash, scalp massage and a glossy, long-lasting blow-dry.',
      durationMinutes: 45,
      price: qar(14950),
    ),
    SalonService(
      id: 102,
      categoryId: 1,
      name: 'Precision Cut & Style',
      description: 'Consultation, tailored cut and finish by a senior stylist.',
      durationMinutes: 60,
      price: qar(28000),
      variants: [
        ServiceOption(id: 1021, name: 'Senior stylist', durationMinutes: 60, price: qar(28000)),
        ServiceOption(id: 1022, name: 'Creative director', durationMinutes: 75, price: qar(42000)),
      ],
    ),
    SalonService(
      id: 201,
      categoryId: 2,
      name: 'Balayage Lumière',
      description: 'Hand-painted, sun-kissed colour with gloss and bond repair.',
      durationMinutes: 180,
      price: qar(115000),
      originalPrice: qar(135000),
      depositType: 'percent',
      depositValue: 30,
    ),
    SalonService(id: 202, categoryId: 2, name: 'Root Touch-up', durationMinutes: 75, price: qar(32000)),
    SalonService(id: 301, categoryId: 3, name: 'Gel Manicure', durationMinutes: 50, price: qar(18000)),
    SalonService(id: 302, categoryId: 3, name: 'Spa Pedicure', durationMinutes: 60, price: qar(22000)),
    SalonService(id: 401, categoryId: 4, name: 'Gold Radiance Facial', durationMinutes: 75, price: qar(65000)),
    SalonService(id: 501, categoryId: 5, name: 'Evening Makeup', durationMinutes: 60, price: qar(45000)),
  ];

  static const staff = [
    StaffMember(
      id: 11,
      name: 'Layla Haddad',
      title: 'Creative Director',
      rating: 4.9,
      ratingsCount: 212,
      branchIds: [1, 2],
      serviceIds: [101, 102, 201, 202],
    ),
    StaffMember(
      id: 12,
      name: 'Sofia Marchetti',
      title: 'Senior Colourist',
      rating: 4.8,
      ratingsCount: 164,
      branchIds: [1],
      serviceIds: [201, 202, 101],
    ),
    StaffMember(
      id: 13,
      name: 'Noor Al-Kuwari',
      title: 'Nail Artist',
      rating: 4.9,
      ratingsCount: 98,
      branchIds: [1, 2, 3],
      serviceIds: [301, 302],
    ),
    StaffMember(
      id: 14,
      name: 'Amira Saleh',
      title: 'Skin Therapist',
      rating: 4.7,
      ratingsCount: 77,
      branchIds: [2, 3],
      serviceIds: [401, 501],
    ),
  ];

  static final client = ClientProfile(
    id: 7,
    name: 'Mariam Al-Thani',
    phone: '+97455501234',
    email: 'mariam@example.com',
    locale: 'en',
    walletBalance: qar(25000),
    loyaltyPoints: 1840,
    loyaltyTier: 'gold',
    referralCode: 'MARIAM25',
    consents: const {'whatsapp': true, 'sms': false, 'email': true, 'push': true},
  );

  static final products = <Product>[
    Product(
      id: 1,
      name: 'Elixir Ultime Oil',
      brand: 'Kérastase',
      price: qar(19500),
      description: 'Lightweight finishing oil for shine and frizz control.',
    ),
    Product(
      id: 2,
      name: 'Bond Repair Mask',
      brand: 'Olaplex',
      price: qar(14000),
      description: 'Weekly reparative treatment for colour-treated hair.',
    ),
    Product(id: 3, name: 'Silk Pillowcase', brand: 'Maison Lumière', price: qar(32000), originalPrice: qar(38000)),
    Product(id: 4, name: 'Cuticle Serum', brand: 'Maison Lumière', price: qar(8500), inStock: false),
  ];

  static final packages = <SalonPackage>[
    SalonPackage(
      id: 1,
      name: 'Blow-dry Ritual ×5',
      price: qar(62500),
      sessions: 5,
      validityDays: 90,
      description: 'Five signature blow-dries, valid 3 months.',
    ),
    SalonPackage(
      id: 2,
      name: 'Bridal Glow',
      price: qar(250000),
      sessions: 4,
      validityDays: 120,
      description: 'Facial series, trial makeup and the big-day look.',
    ),
  ];

  static const styles = <StudioStyle>[
    StudioStyle(id: 1, name: 'Honey Balayage', category: 'hair', serviceId: 201),
    StudioStyle(id: 2, name: 'Espresso Gloss', category: 'hair', serviceId: 202),
    StudioStyle(id: 3, name: 'Parisian Bob', category: 'hair', serviceId: 102),
    StudioStyle(id: 4, name: 'Hollywood Waves', category: 'hair', serviceId: 101),
    StudioStyle(id: 5, name: 'Soft Glam', category: 'makeup', serviceId: 501),
    StudioStyle(id: 6, name: 'Milky Nude', category: 'nails', serviceId: 301),
  ];
}
