class ApiException implements Exception {
  const ApiException(this.code, this.message, {this.status = 0});
  final String code;
  final String message;
  final int status;

  @override
  String toString() => 'ApiException($code, $message)';
}
