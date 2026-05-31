class ApiException implements Exception {
  const ApiException(this.code, this.message, {this.status = 0});
  final String code;
  final String message;
  final int status;

  /// User-facing Arabic message that's friendly to show in snackbars.
  /// Falls back to the server-provided `message` when the code isn't
  /// one we have a localized override for.
  String get userMessage {
    switch (code) {
      case 'timeout':
        return 'الاتصال بطيء — تحقّق من شبكتك وحاول مجدداً';
      case 'offline':
        return 'لا يوجد اتصال بالإنترنت';
      case 'rate_limited':
        return 'تم تجاوز الحد المسموح، حاول بعد قليل';
      case 'unauthorized':
      case 'http_401':
        return 'يلزم تسجيل الدخول للمتابعة';
      case 'forbidden':
      case 'http_403':
        return 'لا تملك صلاحية لهذه العملية';
      case 'not_found':
      case 'http_404':
        return 'العنصر غير موجود';
      case 'http_500':
      case 'http_502':
      case 'http_503':
        return 'الخادم يواجه مشكلة مؤقتة، حاول بعد قليل';
      case 'invalid_response':
        return 'استجابة غير متوقعة من الخادم';
      default:
        return message;
    }
  }

  @override
  String toString() => 'ApiException($code, $message)';
}
