import Foundation

enum RelativeTime {
    private static let iso: ISO8601DateFormatter = {
        let f = ISO8601DateFormatter()
        f.formatOptions = [.withInternetDateTime, .withFractionalSeconds]
        return f
    }()

    private static let mysql: DateFormatter = {
        let f = DateFormatter()
        f.calendar = Calendar(identifier: .gregorian)
        f.locale = Locale(identifier: "en_US_POSIX")
        f.timeZone = TimeZone(identifier: "Asia/Amman")
        f.dateFormat = "yyyy-MM-dd HH:mm:ss"
        return f
    }()

    /// Convert a server timestamp to a short Arabic relative string.
    static func arabic(from raw: String?) -> String {
        guard let raw, let date = parse(raw) else { return "" }
        let seconds = Int(Date().timeIntervalSince(date))
        if seconds < 60 { return "الآن" }
        let minutes = seconds / 60
        if minutes < 60 { return "قبل \(minutes) دقيقة" }
        let hours = minutes / 60
        if hours < 24 { return "قبل \(hours) ساعة" }
        let days = hours / 24
        if days < 7 { return "قبل \(days) يوم" }
        let weeks = days / 7
        if weeks < 5 { return "قبل \(weeks) أسبوع" }
        let months = days / 30
        if months < 12 { return "قبل \(months) شهر" }
        let years = days / 365
        return "قبل \(years) سنة"
    }

    private static func parse(_ s: String) -> Date? {
        if let d = iso.date(from: s) { return d }
        return mysql.date(from: s)
    }
}
