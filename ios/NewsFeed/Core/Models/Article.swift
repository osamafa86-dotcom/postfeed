import Foundation

struct Article: Identifiable, Decodable, Hashable {
    let id: Int
    let title: String
    let slug: String?
    let excerpt: String?
    let content: String?
    let imageURL: String?
    let sourceURL: String?
    let publishedAt: String?
    let viewCount: Int
    let comments: Int
    let isBreaking: Bool
    let isFeatured: Bool
    let category: ArticleCategory
    let source: ArticleSource

    enum CodingKeys: String, CodingKey {
        case id, title, slug, excerpt, content, comments
        case imageURL = "image_url"
        case sourceURL = "source_url"
        case publishedAt = "published_at"
        case viewCount = "view_count"
        case isBreaking = "is_breaking"
        case isFeatured = "is_featured"
        case category, source
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        id = try c.decode(Int.self, forKey: .id)
        title = try c.decodeIfPresent(String.self, forKey: .title) ?? ""
        slug = try c.decodeIfPresent(String.self, forKey: .slug)
        excerpt = try c.decodeIfPresent(String.self, forKey: .excerpt)
        content = try c.decodeIfPresent(String.self, forKey: .content)
        imageURL = try c.decodeIfPresent(String.self, forKey: .imageURL)
        sourceURL = try c.decodeIfPresent(String.self, forKey: .sourceURL)
        publishedAt = try c.decodeIfPresent(String.self, forKey: .publishedAt)
        viewCount = (try? c.decode(Int.self, forKey: .viewCount)) ?? 0
        comments = (try? c.decode(Int.self, forKey: .comments)) ?? 0
        isBreaking = (try? c.decode(Bool.self, forKey: .isBreaking)) ?? false
        isFeatured = (try? c.decode(Bool.self, forKey: .isFeatured)) ?? false
        category = (try? c.decode(ArticleCategory.self, forKey: .category)) ?? .empty
        source = (try? c.decode(ArticleSource.self, forKey: .source)) ?? .empty
    }
}

struct ArticleCategory: Decodable, Hashable {
    let id: Int?
    let name: String?
    let slug: String?
    let icon: String?
    let cssClass: String?

    enum CodingKeys: String, CodingKey { case id, name, slug, icon; case cssClass = "css_class" }

    static let empty = ArticleCategory(id: nil, name: nil, slug: nil, icon: nil, cssClass: nil)
}

struct ArticleSource: Decodable, Hashable {
    let id: Int?
    let name: String?
    let logoLetter: String?
    let logoColor: String?
    let logoBg: String?
    let url: String?

    enum CodingKeys: String, CodingKey {
        case id, name, url
        case logoLetter = "logo_letter"
        case logoColor = "logo_color"
        case logoBg = "logo_bg"
    }

    static let empty = ArticleSource(id: nil, name: nil, logoLetter: nil, logoColor: nil, logoBg: nil, url: nil)
}
