import 'package:dio/dio.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:pretty_dio_logger/pretty_dio_logger.dart';

import '../../features/auth/data/auth_storage.dart';
import 'api_envelope.dart';
import 'api_exception.dart';

/// Override at build-time with --dart-define=API_BASE=https://feedsnews.net/api/v1
const String _defaultBase = 'https://feedsnews.net/api/v1';

class ApiBase {
  static const String url = String.fromEnvironment('API_BASE', defaultValue: _defaultBase);
}

String _platformLabel() {
  if (kIsWeb) return 'web';
  switch (defaultTargetPlatform) {
    case TargetPlatform.iOS:
      return 'ios';
    case TargetPlatform.android:
      return 'android';
    case TargetPlatform.macOS:
      return 'macos';
    case TargetPlatform.windows:
      return 'windows';
    case TargetPlatform.linux:
      return 'linux';
    default:
      return 'other';
  }
}

/// Wraps Dio with the conventions of the v1 API:
/// - Bearer token from AuthStorage
/// - JSON envelope `{ ok, data, meta?, error? }`
/// - Localized error surfacing
class ApiClient {
  ApiClient._(this._dio);

  final Dio _dio;

  static ApiClient create() {
    final dio = Dio(BaseOptions(
      baseUrl: ApiBase.url,
      connectTimeout: const Duration(seconds: 15),
      receiveTimeout: const Duration(seconds: 25),
      sendTimeout: const Duration(seconds: 20),
      headers: {
        'Accept': 'application/json',
        'Content-Type': 'application/json; charset=utf-8',
        'X-Platform': _platformLabel(),
      },
    ));

    dio.interceptors.add(InterceptorsWrapper(
      onRequest: (opts, handler) {
        final token = AuthStorage.token;
        if (token != null && token.isNotEmpty) {
          opts.headers['Authorization'] = 'Bearer $token';
        }
        handler.next(opts);
      },
      onError: (err, handler) async {
        // Auto-refresh once on 401 (token expired but valid signature) — for
        // now we just clear; the user lands on the login flow on next request.
        if (err.response?.statusCode == 401) {
          await AuthStorage.clear();
        }
        handler.next(err);
      },
    ));

    if (kDebugMode) {
      dio.interceptors.add(PrettyDioLogger(
        requestHeader: false,
        requestBody: true,
        responseBody: true,
        compact: true,
        maxWidth: 100,
      ));
    }

    return ApiClient._(dio);
  }

  Dio get raw => _dio;

  Future<ApiEnvelope<T>> get<T>(
    String path, {
    Map<String, dynamic>? query,
    T Function(dynamic)? decode,
  }) async {
    final res = await _safe(() => _dio.get(path, queryParameters: query));
    return ApiEnvelope.from(res.data, decode);
  }

  Future<ApiEnvelope<T>> post<T>(
    String path, {
    Object? body,
    Map<String, dynamic>? query,
    T Function(dynamic)? decode,
  }) async {
    final res = await _safe(() => _dio.post(path, data: body, queryParameters: query));
    return ApiEnvelope.from(res.data, decode);
  }

  Future<ApiEnvelope<T>> patch<T>(
    String path, {
    Object? body,
    T Function(dynamic)? decode,
  }) async {
    final res = await _safe(() => _dio.patch(path, data: body));
    return ApiEnvelope.from(res.data, decode);
  }

  Future<ApiEnvelope<T>> delete<T>(
    String path, {
    Map<String, dynamic>? query,
    T Function(dynamic)? decode,
  }) async {
    final res = await _safe(() => _dio.delete(path, queryParameters: query));
    return ApiEnvelope.from(res.data, decode);
  }

  Future<Response<dynamic>> _safe(Future<Response<dynamic>> Function() run) async {
    try {
      return await run();
    } on DioException catch (e) {
      if (e.type == DioExceptionType.connectionTimeout ||
          e.type == DioExceptionType.receiveTimeout ||
          e.type == DioExceptionType.sendTimeout) {
        throw const ApiException('timeout', 'انتهت مهلة الاتصال — تحقق من الإنترنت');
      }
      if (e.type == DioExceptionType.connectionError) {
        throw const ApiException('offline', 'لا يوجد اتصال بالإنترنت');
      }
      final data = e.response?.data;
      if (data is Map && data['error'] is String) {
        throw ApiException(
          data['error'] as String,
          (data['message'] as String?) ?? 'حدث خطأ في الخادم',
          status: e.response?.statusCode ?? 0,
        );
      }
      throw ApiException(
        'http_${e.response?.statusCode ?? 0}',
        'تعذّر إكمال الطلب',
        status: e.response?.statusCode ?? 0,
      );
    }
  }
}

final apiClientProvider = Provider<ApiClient>((ref) => ApiClient.create());
