import Foundation

/// Thin async/await wrapper around URLSession for the v1 API.
actor APIClient {
    static let shared = APIClient()

    private let session: URLSession
    private let decoder: JSONDecoder
    private let encoder: JSONEncoder

    init(session: URLSession = .shared) {
        self.session = session
        self.decoder = JSONDecoder()
        self.encoder = JSONEncoder()
    }

    // MARK: - Public helpers

    func get<T: Decodable>(_ path: String,
                           query: [String: String?] = [:],
                           authed: Bool = false,
                           as: T.Type = T.self) async throws -> T {
        try await send(path: path, method: "GET", query: query, body: nil, authed: authed)
    }

    func post<T: Decodable>(_ path: String,
                            query: [String: String?] = [:],
                            body: Encodable? = nil,
                            authed: Bool = false,
                            as: T.Type = T.self) async throws -> T {
        try await send(path: path, method: "POST", query: query, body: body, authed: authed)
    }

    func delete<T: Decodable>(_ path: String,
                              query: [String: String?] = [:],
                              authed: Bool = false,
                              as: T.Type = T.self) async throws -> T {
        try await send(path: path, method: "DELETE", query: query, body: nil, authed: authed)
    }

    // MARK: - Core

    private func send<T: Decodable>(path: String,
                                    method: String,
                                    query: [String: String?],
                                    body: Encodable?,
                                    authed: Bool) async throws -> T {
        guard var comps = URLComponents(url: APIConfig.baseURL.appendingPathComponent(path),
                                        resolvingAgainstBaseURL: false) else {
            throw APIError.network("bad_url")
        }
        let filtered = query.compactMap { (k, v) -> URLQueryItem? in
            guard let v else { return nil }
            return URLQueryItem(name: k, value: v)
        }
        if !filtered.isEmpty { comps.queryItems = filtered }

        guard let url = comps.url else { throw APIError.network("bad_url") }
        var req = URLRequest(url: url, timeoutInterval: 25)
        req.httpMethod = method
        req.setValue("application/json", forHTTPHeaderField: "Accept")
        req.setValue(APIConfig.userAgent, forHTTPHeaderField: "User-Agent")
        req.setValue("ios", forHTTPHeaderField: "X-Platform")
        req.setValue(APIConfig.appVersion, forHTTPHeaderField: "X-App-Version")
        req.setValue(Locale.current.identifier, forHTTPHeaderField: "Accept-Language")

        if authed, let token = TokenStore.shared.token {
            req.setValue("Bearer \(token)", forHTTPHeaderField: "Authorization")
        }

        if let body {
            req.setValue("application/json; charset=utf-8", forHTTPHeaderField: "Content-Type")
            req.httpBody = try encoder.encode(AnyEncodable(body))
        }

        let (data, resp): (Data, URLResponse)
        do {
            (data, resp) = try await session.data(for: req)
        } catch {
            throw APIError.network(error.localizedDescription)
        }
        guard let http = resp as? HTTPURLResponse else { throw APIError.unknown }

        if (200..<300).contains(http.statusCode) {
            do {
                return try decoder.decode(T.self, from: data)
            } catch {
                #if DEBUG
                print("Decoding failed for \(path):", error)
                if let s = String(data: data, encoding: .utf8) { print(s) }
                #endif
                throw APIError.decoding(error.localizedDescription)
            }
        }

        // Try to decode the error envelope.
        if let env = try? decoder.decode(ErrorEnvelope.self, from: data) {
            switch http.statusCode {
            case 401: throw APIError.unauthorized
            case 404: throw APIError.notFound
            case 429: throw APIError.rateLimited
            default:  throw APIError.server(code: env.error, message: env.message ?? "", status: http.statusCode)
            }
        }
        switch http.statusCode {
        case 401: throw APIError.unauthorized
        case 404: throw APIError.notFound
        case 429: throw APIError.rateLimited
        default:  throw APIError.server(code: "http_\(http.statusCode)", message: "", status: http.statusCode)
        }
    }
}

private struct ErrorEnvelope: Decodable {
    let ok: Bool?
    let error: String
    let message: String?
}

/// Wraps any Encodable so we can pass it through generic boundaries.
struct AnyEncodable: Encodable {
    private let _encode: (Encoder) throws -> Void
    init<T: Encodable>(_ wrapped: T) {
        _encode = wrapped.encode
    }
    func encode(to encoder: Encoder) throws { try _encode(encoder) }
}
