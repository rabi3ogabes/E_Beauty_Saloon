import 'dart:async';
import 'dart:io';

import 'package:camera/camera.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:image_picker/image_picker.dart';
import 'package:material_ui/material_ui.dart';

import '../../../app/providers.dart';
import '../../../core/storage/key_value_store.dart';
import '../../../core/theme/app_theme.dart';
import '../../../core/widgets/lux_widgets.dart';
import '../../../core/widgets/skeleton.dart';
import '../../../core/widgets/states.dart';
import '../../../data/models/studio.dart';
import '../../../l10n/l10n.dart';

// ---------------------------------------------------------------------------
// Providers
// ---------------------------------------------------------------------------

final studioStylesProvider = FutureProvider.autoDispose.family<List<StudioStyle>, StudioCategory>(
  (ref, category) => ref.watch(studioRepositoryProvider).styles(category: category),
);

/// Polls `GET /studio/jobs/{id}` until the job finishes, backing off gently.
final studioJobProvider = StreamProvider.autoDispose.family<StudioJob, String>((ref, id) async* {
  final repo = ref.watch(studioRepositoryProvider);
  var alive = true;
  ref.onDispose(() => alive = false);
  var delay = const Duration(milliseconds: 1200);
  while (alive) {
    final job = await repo.job(id);
    yield job;
    if (job.isFinished) break;
    await Future<void>.delayed(delay);
    if (delay < const Duration(seconds: 4)) delay *= 1.3;
  }
});

final looksProvider = FutureProvider.autoDispose<List<Look>>((ref) => ref.watch(studioRepositoryProvider).looks());

final studioConsentProvider = FutureProvider.autoDispose<bool>(
  (ref) async => await ref.watch(keyValueStoreProvider).read(StorageKeys.studioConsent) == '1',
);

// ---------------------------------------------------------------------------
// Studio entry: consent → photo → category & style → create job
// ---------------------------------------------------------------------------

class StudioScreen extends ConsumerStatefulWidget {
  const StudioScreen({super.key});

  @override
  ConsumerState<StudioScreen> createState() => _StudioScreenState();
}

class _StudioScreenState extends ConsumerState<StudioScreen> {
  File? _photo;
  StudioCategory _category = StudioCategory.hair;
  StudioStyle? _style;
  bool _busy = false;

  Future<void> _acceptConsent() async {
    await ref.read(keyValueStoreProvider).write(StorageKeys.studioConsent, '1');
    ref.invalidate(studioConsentProvider);
  }

  Future<void> _fromCamera() async {
    final path = await context.push<String>('/studio/camera');
    if (path != null) setState(() => _photo = File(path));
  }

  Future<void> _fromGallery() async {
    try {
      final file = await ImagePicker().pickImage(source: ImageSource.gallery, maxWidth: 1600, imageQuality: 88);
      if (file != null) setState(() => _photo = File(file.path));
    } catch (e) {
      if (mounted) context.showSnack(context.l10n.photoAccessDenied);
    }
  }

  Future<void> _create() async {
    if (_photo == null || _style == null) return;
    if (!ref.read(sessionProvider).isSignedIn) {
      context.push('/sign-in?from=${Uri.encodeComponent('/studio')}');
      return;
    }
    setState(() => _busy = true);
    try {
      final id = await ref.read(studioRepositoryProvider).createJob(photo: _photo!, category: _category, styleId: _style!.id);
      Haptics.success();
      if (mounted) context.pushReplacement('/studio/job/$id');
    } catch (e) {
      if (mounted) context.showSnack(context.errorMessage(e));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final consent = ref.watch(studioConsentProvider);
    return Scaffold(
      appBar: AppBar(
        title: Text(l10n.aiStudio),
        actions: [TextButton(onPressed: () => context.push('/looks'), child: Text(l10n.myLooks))],
      ),
      body: consent.when(
        loading: () => const SkeletonList(count: 3),
        error: (e, _) => ErrorState(error: e),
        data: (ok) => ok ? _buildCreator(context) : _ConsentView(onAccept: _acceptConsent),
      ),
      bottomNavigationBar: consent.asData?.value == true
          ? SafeArea(
              child: Padding(
                padding: const EdgeInsets.fromLTRB(20, 8, 20, 12),
                child: LuxButton(
                  label: l10n.generateLook,
                  icon: Icons.auto_awesome,
                  variant: LuxButtonVariant.gold,
                  busy: _busy,
                  onPressed: _photo != null && _style != null ? _create : null,
                ),
              ),
            )
          : null,
    );
  }

  Widget _buildCreator(BuildContext context) {
    final l10n = context.l10n;
    final text = Theme.of(context).textTheme;
    final tokens = LuxeTokens.of(context);
    final styles = ref.watch(studioStylesProvider(_category));
    final categoryLabels = {
      StudioCategory.hair: l10n.categoryHair,
      StudioCategory.eyes: l10n.categoryEyes,
      StudioCategory.lips: l10n.categoryLips,
      StudioCategory.makeup: l10n.categoryMakeup,
      StudioCategory.nails: l10n.categoryNails,
      StudioCategory.pedicure: l10n.categoryPedicure,
      StudioCategory.bridal: l10n.categoryBridal,
    };

    return ListView(
      padding: const EdgeInsets.all(20),
      children: [
        Eyebrow(l10n.step1Photo),
        const SizedBox(height: 10),
        AspectRatio(
          aspectRatio: 4 / 3,
          child: _photo == null
              ? LuxCard(
                  shadow: false,
                  child: Column(
                    mainAxisAlignment: MainAxisAlignment.center,
                    children: [
                      Icon(Icons.face_retouching_natural, size: 48, color: tokens.goldDark),
                      const SizedBox(height: 12),
                      Text(l10n.photoTip, style: text.bodyMedium, textAlign: TextAlign.center),
                    ],
                  ),
                )
              : Stack(
                  fit: StackFit.expand,
                  children: [
                    AppImage(url: _photo!.path),
                    PositionedDirectional(
                      top: 8,
                      end: 8,
                      child: IconButton.filledTonal(onPressed: () => setState(() => _photo = null), icon: const Icon(Icons.close)),
                    ),
                  ],
                ),
        ),
        const SizedBox(height: 12),
        Row(
          children: [
            Expanded(
              child: LuxButton(
                label: l10n.takePhoto,
                icon: Icons.photo_camera_outlined,
                variant: LuxButtonVariant.secondary,
                onPressed: _fromCamera,
              ),
            ),
            const SizedBox(width: 10),
            Expanded(
              child: LuxButton(
                label: l10n.chooseFromGallery,
                icon: Icons.photo_library_outlined,
                variant: LuxButtonVariant.secondary,
                onPressed: _fromGallery,
              ),
            ),
          ],
        ),
        const SizedBox(height: 28),
        Eyebrow(l10n.step2Style),
        const SizedBox(height: 10),
        Wrap(
          spacing: 8,
          runSpacing: 8,
          children: [
            for (final c in StudioCategory.values)
              ChoiceChip(
                label: Text(categoryLabels[c]!),
                selected: _category == c,
                onSelected: (_) => setState(() {
                  _category = c;
                  _style = null;
                }),
              ),
          ],
        ),
        const SizedBox(height: 16),
        styles.when(
          loading: () => const SkeletonChips(count: 4),
          error: (e, _) => ErrorState(error: e, compact: true, onRetry: () => ref.invalidate(studioStylesProvider(_category))),
          data: (list) => list.isEmpty
              ? EmptyState(icon: Icons.style_outlined, title: l10n.noStyles, compact: true)
              : GridView.builder(
                  shrinkWrap: true,
                  physics: const NeverScrollableScrollPhysics(),
                  itemCount: list.length,
                  gridDelegate: const SliverGridDelegateWithFixedCrossAxisCount(
                    crossAxisCount: 2,
                    mainAxisSpacing: 12,
                    crossAxisSpacing: 12,
                    childAspectRatio: .9,
                  ),
                  itemBuilder: (_, i) {
                    final s = list[i];
                    return LuxCard(
                      padding: const EdgeInsets.all(8),
                      selected: _style?.id == s.id,
                      onTap: () => setState(() => _style = s),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Expanded(
                            child: AppImage(url: s.thumbnailUrl, width: double.infinity, radius: 12, fallbackIcon: Icons.auto_awesome),
                          ),
                          const SizedBox(height: 8),
                          Text(s.name, style: text.titleSmall, maxLines: 1, overflow: TextOverflow.ellipsis),
                        ],
                      ),
                    );
                  },
                ),
        ),
      ],
    );
  }
}

class _ConsentView extends StatefulWidget {
  const _ConsentView({required this.onAccept});

  final Future<void> Function() onAccept;

  @override
  State<_ConsentView> createState() => _ConsentViewState();
}

class _ConsentViewState extends State<_ConsentView> {
  bool _checked = false;

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final text = Theme.of(context).textTheme;
    final tokens = LuxeTokens.of(context);
    Widget point(IconData icon, String s) => Padding(
      padding: const EdgeInsets.only(bottom: 14),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(icon, color: tokens.goldDark, size: 22),
          const SizedBox(width: 12),
          Expanded(child: Text(s, style: text.bodyMedium)),
        ],
      ),
    );

    return ListView(
      padding: const EdgeInsets.all(24),
      children: [
        Icon(Icons.auto_awesome, size: 48, color: tokens.gold),
        const SizedBox(height: 16),
        Text(l10n.studioConsentTitle, style: text.headlineMedium, textAlign: TextAlign.center),
        const SizedBox(height: 24),
        point(Icons.lock_outline, l10n.studioConsentPrivate),
        point(Icons.delete_outline, l10n.studioConsentDelete),
        point(Icons.do_not_disturb_on_outlined, l10n.studioConsentNoTraining),
        point(Icons.info_outline, l10n.studioConsentPreview),
        const SizedBox(height: 8),
        CheckboxListTile(
          value: _checked,
          contentPadding: EdgeInsets.zero,
          controlAffinity: ListTileControlAffinity.leading,
          onChanged: (v) => setState(() => _checked = v ?? false),
          title: Text(l10n.studioConsentCheckbox, style: text.bodyMedium),
        ),
        const SizedBox(height: 12),
        LuxButton(label: l10n.agreeAndContinue, onPressed: _checked ? widget.onAccept : null),
      ],
    );
  }
}

// ---------------------------------------------------------------------------
// Camera with framing overlay (falls back to gallery on devices without camera)
// ---------------------------------------------------------------------------

class StudioCameraScreen extends StatefulWidget {
  const StudioCameraScreen({super.key});

  @override
  State<StudioCameraScreen> createState() => _StudioCameraScreenState();
}

class _StudioCameraScreenState extends State<StudioCameraScreen> with WidgetsBindingObserver {
  CameraController? _controller;
  List<CameraDescription> _cameras = const [];
  int _index = 0;
  Object? _error;
  bool _capturing = false;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    _init();
  }

  Future<void> _init() async {
    try {
      _cameras = await availableCameras();
      if (_cameras.isEmpty) throw StateError('no camera');
      final front = _cameras.indexWhere((c) => c.lensDirection == CameraLensDirection.front);
      _index = front < 0 ? 0 : front;
      await _start();
    } catch (e) {
      if (mounted) setState(() => _error = e);
    }
  }

  Future<void> _start() async {
    final old = _controller;
    final controller = CameraController(_cameras[_index], ResolutionPreset.high, enableAudio: false);
    _controller = controller;
    await old?.dispose();
    await controller.initialize();
    if (mounted) setState(() {});
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    final c = _controller;
    if (c == null || !c.value.isInitialized) return;
    if (state == AppLifecycleState.inactive) {
      c.dispose();
      _controller = null;
    } else if (state == AppLifecycleState.resumed && _cameras.isNotEmpty) {
      _start();
    }
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    _controller?.dispose();
    super.dispose();
  }

  Future<void> _capture() async {
    final c = _controller;
    if (c == null || !c.value.isInitialized || _capturing) return;
    setState(() => _capturing = true);
    try {
      Haptics.light();
      final file = await c.takePicture();
      if (mounted) context.pop(file.path);
    } catch (e) {
      if (mounted) context.showSnack(context.errorMessage(e));
    } finally {
      if (mounted) setState(() => _capturing = false);
    }
  }

  Future<void> _gallery() async {
    final file = await ImagePicker().pickImage(source: ImageSource.gallery, maxWidth: 1600, imageQuality: 88);
    if (file != null && mounted) context.pop(file.path);
  }

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final c = _controller;
    return Scaffold(
      backgroundColor: Colors.black,
      appBar: AppBar(backgroundColor: Colors.black, foregroundColor: Colors.white, title: Text(l10n.framePhoto)),
      body: _error != null
          ? EmptyState(
              icon: Icons.no_photography_outlined,
              title: l10n.cameraUnavailable,
              actionLabel: l10n.chooseFromGallery,
              onAction: _gallery,
            )
          : (c == null || !c.value.isInitialized)
          ? const Center(child: Shimmer(child: SkeletonBox(width: 260, height: 340, radius: 200)))
          : Stack(
              fit: StackFit.expand,
              children: [
                Center(child: CameraPreview(c)),
                const IgnorePointer(child: CustomPaint(painter: _FramingOverlay())),
                Positioned(
                  top: 24,
                  left: 24,
                  right: 24,
                  child: Text(
                    l10n.framingHint,
                    textAlign: TextAlign.center,
                    style: const TextStyle(color: Colors.white, fontSize: 15),
                  ),
                ),
              ],
            ),
      bottomNavigationBar: _error != null
          ? null
          : SafeArea(
              child: SizedBox(
                height: 120,
                child: Row(
                  mainAxisAlignment: MainAxisAlignment.spaceEvenly,
                  children: [
                    IconButton(
                      onPressed: _gallery,
                      icon: const Icon(Icons.photo_library_outlined, color: Colors.white),
                      tooltip: l10n.chooseFromGallery,
                    ),
                    Semantics(
                      button: true,
                      label: l10n.takePhoto,
                      child: GestureDetector(
                        onTap: _capture,
                        child: Container(
                          width: 76,
                          height: 76,
                          decoration: BoxDecoration(
                            shape: BoxShape.circle,
                            border: Border.all(color: Colors.white, width: 4),
                          ),
                          padding: const EdgeInsets.all(4),
                          child: AnimatedContainer(
                            duration: const Duration(milliseconds: 120),
                            decoration: BoxDecoration(shape: BoxShape.circle, color: _capturing ? LuxeColors.champagne : Colors.white),
                          ),
                        ),
                      ),
                    ),
                    IconButton(
                      tooltip: l10n.switchCamera,
                      onPressed: _cameras.length < 2
                          ? null
                          : () {
                              _index = (_index + 1) % _cameras.length;
                              _start();
                            },
                      icon: const Icon(Icons.cameraswitch_outlined, color: Colors.white),
                    ),
                  ],
                ),
              ),
            ),
    );
  }
}

/// Dims everything outside a face-shaped oval with a champagne outline.
class _FramingOverlay extends CustomPainter {
  const _FramingOverlay();

  @override
  void paint(Canvas canvas, Size size) {
    final oval = Rect.fromCenter(center: size.center(const Offset(0, -20)), width: size.width * .68, height: size.height * .52);
    final path = Path()
      ..fillType = PathFillType.evenOdd
      ..addRect(Offset.zero & size)
      ..addOval(oval);
    canvas.drawPath(path, Paint()..color = Colors.black.withValues(alpha: .55));
    canvas.drawOval(
      oval,
      Paint()
        ..style = PaintingStyle.stroke
        ..strokeWidth = 2
        ..color = LuxeColors.champagne,
    );
  }

  @override
  bool shouldRepaint(covariant CustomPainter oldDelegate) => false;
}

// ---------------------------------------------------------------------------
// Job progress + before/after
// ---------------------------------------------------------------------------

class StudioJobScreen extends ConsumerWidget {
  const StudioJobScreen({super.key, required this.jobId});

  final String jobId;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l10n = context.l10n;
    final job = ref.watch(studioJobProvider(jobId));
    final text = Theme.of(context).textTheme;
    final tokens = LuxeTokens.of(context);

    return Scaffold(
      appBar: AppBar(title: Text(l10n.yourLook)),
      body: job.when(
        loading: () => const _Generating(progress: null),
        error: (e, _) => ErrorState(error: e, onRetry: () => ref.invalidate(studioJobProvider(jobId))),
        data: (j) {
          if (j.status == StudioJobStatus.failed) {
            return EmptyState(
              icon: Icons.sentiment_dissatisfied_outlined,
              title: l10n.lookFailedTitle,
              message: j.error ?? l10n.lookFailedMessage,
              actionLabel: l10n.tryAgain,
              onAction: () => context.pushReplacement('/studio'),
            );
          }
          if (!j.isFinished) return _Generating(progress: j.progress, styleName: j.styleName);
          return ListView(
            padding: const EdgeInsets.all(20),
            children: [
              AspectRatio(
                aspectRatio: 3 / 4,
                child: BeforeAfterSlider(beforeUrl: j.beforeUrl, afterUrl: j.afterUrl),
              ),
              const SizedBox(height: 20),
              if (j.styleName != null) Text(j.styleName!, style: text.headlineSmall),
              if (j.price != null) Text(l10n.fromPrice(context.money(j.price!)), style: text.titleMedium?.copyWith(color: tokens.goldDark)),
              const SizedBox(height: 8),
              Text(l10n.previewDisclaimer, style: text.bodySmall),
            ],
          );
        },
      ),
      bottomNavigationBar: job.asData?.value.status == StudioJobStatus.completed
          ? SafeArea(
              child: Padding(
                padding: const EdgeInsets.fromLTRB(20, 8, 20, 12),
                child: LuxButton(
                  label: l10n.bookThisLook,
                  icon: Icons.calendar_today_outlined,
                  onPressed: () {
                    final sid = job.asData!.value.serviceId;
                    context.push(sid == null ? '/book' : '/book?services=$sid');
                  },
                ),
              ),
            )
          : null,
    );
  }
}

class _Generating extends StatelessWidget {
  const _Generating({required this.progress, this.styleName});

  final double? progress;
  final String? styleName;

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final text = Theme.of(context).textTheme;
    final tokens = LuxeTokens.of(context);
    final messages = [l10n.generating1, l10n.generating2, l10n.generating3];
    final idx = progress == null ? 0 : (progress! * messages.length).floor().clamp(0, messages.length - 1);
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(32),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            SizedBox(
              width: 160,
              height: 160,
              child: TweenAnimationBuilder<double>(
                tween: Tween(begin: 0, end: progress ?? .08),
                duration: const Duration(milliseconds: 800),
                builder: (_, v, _) => CustomPaint(
                  painter: _RingPainter(v, tokens.gold, tokens.hairline),
                  child: Center(child: Icon(Icons.auto_awesome, size: 40, color: tokens.gold)),
                ),
              ),
            ),
            const SizedBox(height: 28),
            Text(styleName ?? l10n.aiStudio, style: text.headlineSmall),
            const SizedBox(height: 8),
            AnimatedSwitcher(
              duration: const Duration(milliseconds: 400),
              child: Text(
                messages[idx],
                key: ValueKey(idx),
                style: text.bodyMedium?.copyWith(color: tokens.muted),
                textAlign: TextAlign.center,
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _RingPainter extends CustomPainter {
  _RingPainter(this.value, this.color, this.track);

  final double value;
  final Color color;
  final Color track;

  @override
  void paint(Canvas canvas, Size size) {
    final rect = Offset.zero & size;
    final stroke = Paint()
      ..style = PaintingStyle.stroke
      ..strokeWidth = 3
      ..strokeCap = StrokeCap.round;
    canvas.drawArc(rect.deflate(4), 0, 6.283, false, stroke..color = track);
    canvas.drawArc(rect.deflate(4), -1.5708, 6.283 * value, false, stroke..color = color);
  }

  @override
  bool shouldRepaint(_RingPainter old) => old.value != value || old.color != color;
}

/// Drag the handle to compare. Always LTR (before on the left) for clarity.
class BeforeAfterSlider extends StatefulWidget {
  const BeforeAfterSlider({super.key, this.beforeUrl, this.afterUrl});

  final String? beforeUrl;
  final String? afterUrl;

  @override
  State<BeforeAfterSlider> createState() => _BeforeAfterSliderState();
}

class _BeforeAfterSliderState extends State<BeforeAfterSlider> {
  double _split = .5;

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final tokens = LuxeTokens.of(context);
    // Mock jobs return the same photo; tint the "after" so the preview reads as a change.
    final mockTint = widget.beforeUrl != null && widget.beforeUrl == widget.afterUrl;
    Widget after = AppImage(url: widget.afterUrl, radius: 0);
    if (mockTint) {
      after = ColorFiltered(colorFilter: ColorFilter.mode(tokens.gold.withValues(alpha: .28), BlendMode.softLight), child: after);
    }

    return Directionality(
      textDirection: TextDirection.ltr,
      child: ClipRRect(
        borderRadius: BorderRadius.circular(LuxeRadius.card),
        child: LayoutBuilder(
          builder: (context, c) {
            final x = c.maxWidth * _split;
            return GestureDetector(
              onHorizontalDragUpdate: (d) => setState(() => _split = (d.localPosition.dx / c.maxWidth).clamp(.02, .98)),
              onTapDown: (d) => setState(() => _split = (d.localPosition.dx / c.maxWidth).clamp(.02, .98)),
              child: Semantics(
                slider: true,
                value: '${(_split * 100).round()}%',
                label: '${l10n.before} / ${l10n.after}',
                child: Stack(
                  fit: StackFit.expand,
                  children: [
                    after,
                    ClipRect(
                      clipper: _LeftClipper(x),
                      child: AppImage(url: widget.beforeUrl, radius: 0),
                    ),
                    Positioned(
                      left: x - 1,
                      top: 0,
                      bottom: 0,
                      child: Container(width: 2, color: Colors.white),
                    ),
                    Positioned(
                      left: x - 22,
                      top: c.maxHeight / 2 - 22,
                      child: Container(
                        width: 44,
                        height: 44,
                        decoration: BoxDecoration(shape: BoxShape.circle, color: Colors.white, boxShadow: tokens.shadow),
                        child: const Icon(Icons.compare_arrows, color: LuxeColors.ink),
                      ),
                    ),
                    Positioned(left: 12, top: 12, child: _Tag(l10n.before)),
                    Positioned(right: 12, top: 12, child: _Tag(l10n.after)),
                  ],
                ),
              ),
            );
          },
        ),
      ),
    );
  }
}

class _LeftClipper extends CustomClipper<Rect> {
  _LeftClipper(this.x);

  final double x;

  @override
  Rect getClip(Size size) => Rect.fromLTWH(0, 0, x, size.height);

  @override
  bool shouldReclip(_LeftClipper old) => old.x != x;
}

class _Tag extends StatelessWidget {
  const _Tag(this.label);

  final String label;

  @override
  Widget build(BuildContext context) => Container(
    padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
    decoration: BoxDecoration(color: Colors.black.withValues(alpha: .45), borderRadius: BorderRadius.circular(LuxeRadius.pill)),
    child: Text(label.toUpperCase(), style: const TextStyle(color: Colors.white, fontSize: 11, letterSpacing: 1.2)),
  );
}

class LooksScreen extends ConsumerWidget {
  const LooksScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l10n = context.l10n;
    final looks = ref.watch(looksProvider);
    final text = Theme.of(context).textTheme;
    return Scaffold(
      appBar: AppBar(title: Text(l10n.myLooks)),
      body: AsyncValueView<List<Look>>(
        value: looks,
        onRetry: () => ref.invalidate(looksProvider),
        data: (list) => list.isEmpty
            ? EmptyState(
                icon: Icons.auto_awesome_outlined,
                title: l10n.noLooksTitle,
                message: l10n.noLooksMessage,
                actionLabel: l10n.tryALook,
                onAction: () => context.push('/studio'),
              )
            : GridView.builder(
                padding: const EdgeInsets.all(20),
                itemCount: list.length,
                gridDelegate: const SliverGridDelegateWithFixedCrossAxisCount(
                  crossAxisCount: 2,
                  mainAxisSpacing: 12,
                  crossAxisSpacing: 12,
                  childAspectRatio: .72,
                ),
                itemBuilder: (context, i) {
                  final look = list[i];
                  return LuxCard(
                    padding: const EdgeInsets.all(8),
                    onTap: () => showModalBottomSheet<void>(
                      context: context,
                      isScrollControlled: true,
                      builder: (_) => Padding(
                        padding: const EdgeInsets.fromLTRB(20, 0, 20, 24),
                        child: Column(
                          mainAxisSize: MainAxisSize.min,
                          children: [
                            AspectRatio(
                              aspectRatio: 3 / 4,
                              child: BeforeAfterSlider(beforeUrl: look.beforeUrl, afterUrl: look.afterUrl),
                            ),
                            const SizedBox(height: 16),
                            LuxButton(
                              label: l10n.bookThisLook,
                              onPressed: () {
                                Navigator.pop(context);
                                context.push(look.serviceId == null ? '/book' : '/book?services=${look.serviceId}');
                              },
                            ),
                          ],
                        ),
                      ),
                    ),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Expanded(
                          child: AppImage(url: look.afterUrl, width: double.infinity, radius: 12),
                        ),
                        const SizedBox(height: 8),
                        Text(look.styleName ?? l10n.yourLook, style: text.titleSmall, maxLines: 1, overflow: TextOverflow.ellipsis),
                        if (look.createdAt != null) Text(context.shortDate(look.createdAt!), style: text.bodySmall),
                      ],
                    ),
                  );
                },
              ),
      ),
    );
  }
}
