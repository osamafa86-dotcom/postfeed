import 'api_exception.dart';

/// Mirrors the PHP `{ ok, data, meta?, error?, message? }` envelope.
class ApiEnvelope<T> {
  const ApiEnvelope({required this.ok, this.data, this.meta, this.error, this.message});

  final bool ok;
  final T? data;
  final Map<String, dynamic>? meta;
  final String? error;
  final String? message;

  factory ApiEnvelope.from(dynamic raw, T Function(dynamic)? decode) {
    if (raw is! Map) {
      throw const ApiException('bad_response', 'استجابة غير صالحة من الخادم');
    }
    final ok = raw['ok'] == true;
    if (!ok) {
      throw ApiException(
        (raw['error'] as String?) ?? 'server_error',
        (raw['message'] as String?) ?? 'حدث خطأ غير متوقع',
      );
    }
    final dataNode = raw['data'];
    final decoded = (decode != null && dataNode != null) ? decode(dataNode) : dataNode as T?;
    return ApiEnvelope(
      ok: ok,
      data: decoded,
      meta: (raw['meta'] as Map?)?.cast<String, dynamic>(),
    );
  }

  bool get hasMore => (meta?['has_more'] as bool?) ?? false;
  int get total    => (meta?['total'] as int?) ?? 0;
  int get page     => (meta?['page']  as int?) ?? 1;
  int get limit    => (meta?['limit'] as int?) ?? 20;
}
