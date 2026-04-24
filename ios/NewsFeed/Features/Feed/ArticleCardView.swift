import SwiftUI

struct ArticleCardView: View {
    let article: Article

    var body: some View {
        VStack(alignment: .leading, spacing: 10) {
            if article.imageURL != nil {
                RemoteImage(url: article.imageURL)
                    .frame(height: 200)
                    .clipped()
                    .clipShape(RoundedRectangle(cornerRadius: 14, style: .continuous))
            }

            HStack(spacing: 8) {
                if article.isBreaking {
                    Label("عاجل", systemImage: "bolt.fill")
                        .font(.caption.bold())
                        .padding(.horizontal, 8)
                        .padding(.vertical, 4)
                        .background(Color.breakingRed)
                        .foregroundColor(.white)
                        .clipShape(Capsule())
                }
                if let name = article.category.name {
                    Text(name)
                        .font(.caption.bold())
                        .padding(.horizontal, 8)
                        .padding(.vertical, 4)
                        .background(Color.category(article.category.slug).opacity(0.15))
                        .foregroundColor(Color.category(article.category.slug))
                        .clipShape(Capsule())
                }
                Spacer(minLength: 0)
                Text(RelativeTime.arabic(from: article.publishedAt))
                    .font(.caption)
                    .foregroundColor(.secondary)
            }

            Text(article.title)
                .font(.system(size: 18, weight: .bold))
                .foregroundColor(.primary)
                .lineLimit(3)
                .multilineTextAlignment(.leading)

            if let excerpt = article.excerpt, !excerpt.isEmpty {
                Text(excerpt)
                    .font(.system(size: 15))
                    .foregroundColor(.secondary)
                    .lineLimit(2)
            }

            HStack(spacing: 12) {
                SourceBadge(source: article.source)
                Spacer()
                Label(compact(article.viewCount), systemImage: "eye.fill")
                    .font(.caption)
                    .foregroundColor(.secondary)
                if article.comments > 0 {
                    Label("\(article.comments)", systemImage: "bubble.left.fill")
                        .font(.caption)
                        .foregroundColor(.secondary)
                }
            }
        }
        .padding(14)
        .background(
            RoundedRectangle(cornerRadius: 18, style: .continuous)
                .fill(Color(.systemBackground))
                .shadow(color: .black.opacity(0.04), radius: 6, x: 0, y: 2)
        )
        .padding(.horizontal, 16)
    }

    private func compact(_ n: Int) -> String {
        if n >= 1_000_000 { return String(format: "%.1fم", Double(n) / 1_000_000) }
        if n >= 1_000     { return String(format: "%.1fك", Double(n) / 1_000) }
        return "\(n)"
    }
}

struct SourceBadge: View {
    let source: ArticleSource
    var body: some View {
        HStack(spacing: 6) {
            ZStack {
                Circle()
                    .fill(Color(hex: source.logoBg) ?? Color.brand.opacity(0.15))
                    .frame(width: 22, height: 22)
                Text(source.logoLetter ?? "ن")
                    .font(.system(size: 12, weight: .bold))
                    .foregroundColor(Color(hex: source.logoColor) ?? .brand)
            }
            Text(source.name ?? "")
                .font(.caption.bold())
                .foregroundColor(.secondary)
                .lineLimit(1)
        }
    }
}
