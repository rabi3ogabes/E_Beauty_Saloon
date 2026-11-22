import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:material_ui/material_ui.dart';

import '../../l10n/l10n.dart';
import '../api/api_exception.dart';
import '../theme/app_theme.dart';
import 'lux_widgets.dart';
import 'skeleton.dart';

/// Renders an [AsyncValue] with a skeleton while loading and a retryable error state.
class AsyncValueView<T> extends StatelessWidget {
  const AsyncValueView({super.key, required this.value, required this.data, this.loading, this.onRetry});

  final AsyncValue<T> value;
  final Widget Function(T data) data;
  final Widget Function()? loading;
  final VoidCallback? onRetry;

  @override
  Widget build(BuildContext context) {
    return value.when(
      skipLoadingOnRefresh: true,
      skipLoadingOnReload: true,
      data: data,
      loading: loading ?? () => const SkeletonList(),
      error: (e, _) => ErrorState(error: e, onRetry: onRetry),
    );
  }
}

class ErrorState extends StatelessWidget {
  const ErrorState({super.key, required this.error, this.onRetry, this.compact = false});

  final Object error;
  final VoidCallback? onRetry;
  final bool compact;

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final module = error is ApiException && (error as ApiException).kind == ApiErrorKind.moduleDisabled;
    return EmptyState(
      icon: module ? Icons.lock_outline : Icons.cloud_off_outlined,
      title: module ? l10n.moduleUnavailableTitle : l10n.errorTitle,
      message: context.errorMessage(error),
      actionLabel: onRetry == null || module ? null : l10n.retry,
      onAction: onRetry,
      compact: compact,
    );
  }
}

class EmptyState extends StatelessWidget {
  const EmptyState({
    super.key,
    required this.icon,
    required this.title,
    this.message,
    this.actionLabel,
    this.onAction,
    this.compact = false,
  });

  final IconData icon;
  final String title;
  final String? message;
  final String? actionLabel;
  final VoidCallback? onAction;
  final bool compact;

  @override
  Widget build(BuildContext context) {
    final tokens = LuxeTokens.of(context);
    final text = Theme.of(context).textTheme;
    return Center(
      child: Padding(
        padding: EdgeInsets.all(compact ? 16 : 32),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Container(
              width: 72,
              height: 72,
              decoration: BoxDecoration(shape: BoxShape.circle, color: tokens.rose.withValues(alpha: .45)),
              child: Icon(icon, color: tokens.goldDark, size: 30),
            ),
            const SizedBox(height: 18),
            Text(title, style: text.titleLarge, textAlign: TextAlign.center),
            if (message != null) ...[
              const SizedBox(height: 8),
              Text(
                message!,
                style: text.bodyMedium?.copyWith(color: tokens.muted),
                textAlign: TextAlign.center,
              ),
            ],
            if (actionLabel != null) ...[
              const SizedBox(height: 20),
              LuxButton(label: actionLabel!, onPressed: onAction, variant: LuxButtonVariant.secondary, expand: false),
            ],
          ],
        ),
      ),
    );
  }
}
