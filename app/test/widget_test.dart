import 'package:flutter_test/flutter_test.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'package:feedsnews/main.dart';

void main() {
  testWidgets('App launches smoke test', (WidgetTester tester) async {
    await tester.pumpWidget(const ProviderScope(child: FeedsNewsApp()));
    // Just verify the app builds without crashing
    expect(find.byType(FeedsNewsApp), findsOneWidget);
  });
}
