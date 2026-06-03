import 'dart:async';

import 'package:app_links/app_links.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:hive_flutter/hive_flutter.dart';
import 'package:timeago/timeago.dart' as timeago;

import 'core/api/api_client.dart';
import 'core/debug/debug_overlay.dart';
import 'core/debug/debug_state.dart';
import 'core/notifications/push_service.dart';
import 'core/router/app_router.dart';
import 'core/storage/hive_init.dart';
import 'core/theme/app_theme.dart';
import 'core/theme/theme_controller.dart';
import 'features/auth/data/auth_storage.dart';

Future<void> main() async {
  // Wrap everything in a zone guard so even errors that escape
  // FlutterError.onError (Dart-level zone errors, e.g. inside
  // microtasks or post-frame callbacks) get captured in the trace
  // overlay. The previous build saw "_TypeError: Null check operator"
  // fire on /weekly but the on-screen log showed only the post-error
  // ErrorWidget render — the original Dart-level exception was
  // never recorded because it was thrown outside Flutter's zone.
  await runZonedGuarded<Future<void>>(() async {
    WidgetsFlutterBinding.ensureInitialized();
    SystemChrome.setSystemUIOverlayStyle(
      const SystemUiOverlayStyle(statusBarColor: Colors.transparent),
    );

    // ── Catch every framework error and surface it in the on-screen
    //    trace overlay. Without this, a build-time throw on iOS release
    //    just produces a silent blank screen — exactly the symptom we've
    //    been chasing.
    final originalOnError = FlutterError.onError;
    FlutterError.onError = (FlutterErrorDetails details) {
      DebugTrace.log(
        'error',
        '${details.exception.runtimeType}: ${details.exceptionAsString().split('\n').first}',
        level: DebugLevel.error,
      );
      // Also capture a short stack frame to identify which file is
      // throwing (in release the names are obfuscated but the structure
      // still helps narrow it down).
      final stackLine = details.stack?.toString().split('\n').take(3).join(' | ') ?? '';
      if (stackLine.isNotEmpty) {
        DebugTrace.log('error.stack', stackLine, level: DebugLevel.error);
      }
      originalOnError?.call(details);
    };
    ErrorWidget.builder = (FlutterErrorDetails details) {
      DebugTrace.log(
        'error.widget',
        details.exceptionAsString().split('\n').first,
        level: DebugLevel.error,
      );
      return Container(
        color: const Color(0xFF7F1D1D),
        alignment: Alignment.center,
        padding: const EdgeInsets.all(16),
        child: Text(
          'ERROR WIDGET\n${details.exceptionAsString()}',
          style: const TextStyle(color: Colors.white, fontSize: 10),
          textAlign: TextAlign.center,
        ),
      );
    };
    DebugTrace.log('boot', 'app starting');

    timeago.setLocaleMessages('ar', timeago.ArMessages());

    await Hive.initFlutter();
    await initHiveBoxes();
    await AuthStorage.init();

    runApp(const ProviderScope(child: FeedsNewsApp()));
  }, (Object error, StackTrace stack) {
    // Last-chance handler for anything that escaped the framework's
    // own onError (zone-level errors, microtask exceptions, etc.).
    DebugTrace.log(
      'error.zone',
      '${error.runtimeType}: ${error.toString().split('\n').first}',
      level: DebugLevel.error,
    );
    final stackLine = stack.toString().split('\n').take(3).join(' | ');
    DebugTrace.log('error.zone.stack', stackLine, level: DebugLevel.error);
  });
}

class FeedsNewsApp extends ConsumerStatefulWidget {
  const FeedsNewsApp({super.key});
  @override
  ConsumerState<FeedsNewsApp> createState() => _FeedsNewsAppState();
}

class _FeedsNewsAppState extends ConsumerState<FeedsNewsApp> {
  StreamSubscription<Uri>? _deepLinkSub;

  @override
  void initState() {
    super.initState();
    // Defer to the next frame so the router is mounted before we
    // try to push or navigate from a cold-start deep link or a
    // notification tap.
    WidgetsBinding.instance.addPostFrameCallback((_) {
      _bootstrapPostStart();
    });
  }

  Future<void> _bootstrapPostStart() async {
    // Push notifications: init in a try/catch so a missing
    // GoogleService-Info.plist or unavailable Firebase doesn't block
    // the rest of app startup. The init is also a no-op (defers token
    // upload) when the user isn't signed in yet.
    try {
      final api = ref.read(apiClientProvider);
      await PushService.init(api: api);
    } catch (e) {
      debugPrint('PushService.init failed: $e');
    }

    // Universal Links: feedsnews.net/article/123 → /article/123.
    try {
      final links = AppLinks();
      final initial = await links.getInitialLink();
      if (initial != null) _handleDeepLink(initial);
      _deepLinkSub = links.uriLinkStream.listen(_handleDeepLink);
    } catch (e) {
      debugPrint('AppLinks init failed: $e');
    }
  }

  void _handleDeepLink(Uri uri) {
    // Only handle our own host; ignore custom schemes / external.
    final host = uri.host.toLowerCase();
    if (host != 'feedsnews.net' && host != 'www.feedsnews.net') return;
    final path = uri.path.isEmpty ? '/' : uri.path;
    final query = uri.query.isEmpty ? '' : '?${uri.query}';
    final ctx = rootNavigatorKey.currentContext;
    if (ctx == null || !ctx.mounted) return;
    // Use a frame delay — same reason as the 401 handler.
    WidgetsBinding.instance.addPostFrameCallback((_) {
      final c = rootNavigatorKey.currentContext;
      if (c != null) c.push(path + query);
    });
  }

  @override
  void dispose() {
    _deepLinkSub?.cancel();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final mode = ref.watch(themeModeControllerProvider);
    final router = ref.watch(appRouterProvider);

    return MaterialApp.router(
      title: 'فيد نيوز',
      debugShowCheckedModeBanner: false,
      themeMode: mode,
      theme: AppTheme.light(),
      darkTheme: AppTheme.dark(),
      routerConfig: router,
      locale: const Locale('ar'),
      supportedLocales: const [Locale('ar')],
      localizationsDelegates: const [
        GlobalMaterialLocalizations.delegate,
        GlobalCupertinoLocalizations.delegate,
        GlobalWidgetsLocalizations.delegate,
      ],
      builder: (context, child) {
        return Directionality(
          textDirection: TextDirection.rtl,
          child: MediaQuery(
            data: MediaQuery.of(context).copyWith(
              textScaler: MediaQuery.of(context).textScaler.clamp(
                    minScaleFactor: 0.85,
                    maxScaleFactor: 1.3,
                  ),
            ),
            // The trace overlay floats above every route so a single
            // screenshot of the blank screen captures the active route,
            // probes, and the last few events.
            child: Stack(
              children: [
                child ?? const SizedBox.shrink(),
                const DebugOverlay(),
              ],
            ),
          ),
        );
      },
    );
  }
}
