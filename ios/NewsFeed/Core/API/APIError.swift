import Foundation

enum APIError: LocalizedError, Equatable {
    case network(String)
    case decoding(String)
    case server(code: String, message: String, status: Int)
    case unauthorized
    case rateLimited
    case notFound
    case unknown

    var errorDescription: String? {
        switch self {
        case .network(let msg):    return "تعذّر الاتصال بالإنترنت. \(msg)"
        case .decoding:            return "خطأ في قراءة البيانات من الخادم."
        case .server(_, let msg, _): return msg.isEmpty ? "حدث خطأ في الخادم." : msg
        case .unauthorized:        return "انتهت جلستك، الرجاء تسجيل الدخول."
        case .rateLimited:         return "تم تجاوز الحد المسموح. حاول بعد قليل."
        case .notFound:            return "العنصر غير موجود."
        case .unknown:             return "حدث خطأ غير متوقع."
        }
    }
}
