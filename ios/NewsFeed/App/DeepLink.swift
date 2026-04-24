import SwiftUI

struct DeepLink: Equatable {
    let url: URL

    var articleId: Int? {
        guard url.scheme == "newsfeed" else { return nil }
        if url.host == "article" {
            let last = url.lastPathComponent
            return Int(last)
        }
        if let comps = URLComponents(url: url, resolvingAgainstBaseURL: false),
           let id = comps.queryItems?.first(where: { $0.name == "id" })?.value {
            return Int(id)
        }
        return nil
    }
}

private struct DeepLinkKey: EnvironmentKey {
    static let defaultValue: DeepLink? = nil
}

extension EnvironmentValues {
    var deepLink: DeepLink? {
        get { self[DeepLinkKey.self] }
        set { self[DeepLinkKey.self] = newValue }
    }
}
