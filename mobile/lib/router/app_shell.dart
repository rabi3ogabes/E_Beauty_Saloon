import 'package:go_router/go_router.dart';
import 'package:material_ui/material_ui.dart';

import '../core/widgets/lux_widgets.dart';

class ShellDestination {
  const ShellDestination({required this.icon, required this.selectedIcon, required this.label});

  final IconData icon;
  final IconData selectedIcon;
  final String label;
}

/// Bottom navigation shell for a [StatefulShellRoute].
class AppShell extends StatelessWidget {
  const AppShell({super.key, required this.shell, required this.destinations});

  final StatefulNavigationShell shell;
  final List<ShellDestination> destinations;

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: shell,
      bottomNavigationBar: NavigationBar(
        selectedIndex: shell.currentIndex,
        onDestinationSelected: (i) {
          Haptics.tap();
          shell.goBranch(i, initialLocation: i == shell.currentIndex);
        },
        destinations: [
          for (final d in destinations) NavigationDestination(icon: Icon(d.icon), selectedIcon: Icon(d.selectedIcon), label: d.label),
        ],
      ),
    );
  }
}
