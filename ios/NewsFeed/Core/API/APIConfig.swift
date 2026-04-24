import Foundation

enum APIConfig {
    /// Base URL of the NewsFeed v1 API. Override via `API_BASE_URL` in Info.plist
    /// if you host the backend on a different domain (e.g. staging).
    static let baseURL: URL = {
        if let override = Bundle.main.object(forInfoDictionaryKey: "API_BASE_URL") as? String,
           let url = URL(string: override) {
            return url
        }
        return URL(string: "https://feedsnews.net/api/v1")!
    }()

    static let webBaseURL: URL = URL(string: "https://feedsnews.net")!

    static var userAgent: String {
        let version = Bundle.main.object(forInfoDictionaryKey: "CFBundleShortVersionString") as? String ?? "1.0"
        let build = Bundle.main.object(forInfoDictionaryKey: "CFBundleVersion") as? String ?? "1"
        return "NewsFeed/\(version) (\(build); iOS)"
    }

    static var appVersion: String {
        let v = Bundle.main.object(forInfoDictionaryKey: "CFBundleShortVersionString") as? String ?? "1.0"
        let b = Bundle.main.object(forInfoDictionaryKey: "CFBundleVersion") as? String ?? "1"
        return "\(v) (\(b))"
    }
}
