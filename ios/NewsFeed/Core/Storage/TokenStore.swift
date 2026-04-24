import Foundation

/// Thread-safe accessor for the current Bearer token. Backed by Keychain.
final class TokenStore: @unchecked Sendable {
    static let shared = TokenStore()

    private let queue = DispatchQueue(label: "TokenStore")
    private var _token: String?
    private let key = "net.feedsnews.newsfeed.auth_token"

    private init() {
        _token = Keychain.get(key)
    }

    var token: String? {
        queue.sync { _token }
    }

    var hasToken: Bool { token != nil }

    func set(_ newValue: String?) {
        queue.sync {
            _token = newValue
            if let v = newValue {
                Keychain.set(v, for: key)
            } else {
                Keychain.remove(key)
            }
        }
    }
}
