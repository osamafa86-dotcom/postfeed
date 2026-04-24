import SwiftUI

struct TrendingView: View {
    @State private var items: [TrendingItem] = []
    @State private var readersNow: Int = 0
    @State private var isLoading = false
    @State private var errorMessage: String?

    var body: some View {
        ScrollView {
            LazyVStack(spacing: 12) {
                if readersNow > 0 {
                    HStack(spacing: 6) {
                        Circle().fill(Color.green).frame(width: 8, height: 8)
                        Text("\(readersNow) يتصفحون الآن")
                            .font(.system(size: 13, weight: .medium))
                            .foregroundColor(.secondary)
                    }
                    .padding(.top, 8)
                }

                if isLoading && items.isEmpty {
                    ProgressView().padding(.top, 40)
                } else if let err = errorMessage, items.isEmpty {
                    ErrorState(message: err) { Task { await load() } }
                } else {
                    ForEach(items.enumerated().map { $0 }, id: \.offset) { pair in
                        let rank = pair.offset + 1
                        TrendingRow(rank: rank, item: pair.element)
                    }
                }
            }
            .padding(.vertical, 12)
        }
        .navigationTitle("الأكثر قراءة")
        .navigationBarTitleDisplayMode(.inline)
        .refreshable { await load() }
        .task { if items.isEmpty { await load() } }
        .navigationDestination(for: Int.self) { id in ArticleDetailView(articleId: id) }
    }

    private func load() async {
        isLoading = true; errorMessage = nil
        defer { isLoading = false }
        do {
            let resp = try await Endpoints.trending(limit: 20)
            items = resp.items
            readersNow = resp.readersNow
        } catch {
            errorMessage = (error as? APIError)?.errorDescription ?? error.localizedDescription
        }
    }
}

struct TrendingRow: View {
    let rank: Int
    let item: TrendingItem

    var body: some View {
        let destinationId = item.id
        Group {
            if let id = destinationId {
                NavigationLink(value: id) { content }.buttonStyle(.plain)
            } else {
                content
            }
        }
    }

    private var content: some View {
        HStack(alignment: .top, spacing: 12) {
            Text("\(rank)")
                .font(.system(size: 28, weight: .heavy))
                .foregroundColor(.brand.opacity(0.7))
                .frame(width: 42)

            if let url = item.imageURL {
                RemoteImage(url: url)
                    .frame(width: 84, height: 84)
                    .clipShape(RoundedRectangle(cornerRadius: 10))
            }

            VStack(alignment: .leading, spacing: 6) {
                if let cat = item.category {
                    Text(cat)
                        .font(.caption.bold())
                        .padding(.horizontal, 6).padding(.vertical, 3)
                        .background(Color.category(item.categorySlug).opacity(0.15))
                        .foregroundColor(Color.category(item.categorySlug))
                        .clipShape(Capsule())
                }
                Text(item.title)
                    .font(.system(size: 15, weight: .semibold))
                    .lineLimit(3)
                    .multilineTextAlignment(.leading)
                HStack(spacing: 10) {
                    if let src = item.source {
                        Text(src).font(.caption).foregroundColor(.secondary)
                    }
                    Label(compact(item.viewsLastHour), systemImage: "flame.fill")
                        .font(.caption)
                        .foregroundColor(.orange)
                }
            }
            Spacer(minLength: 0)
        }
        .padding(12)
        .background(RoundedRectangle(cornerRadius: 14).fill(Color(.systemBackground))
            .shadow(color: .black.opacity(0.04), radius: 6, y: 2))
        .padding(.horizontal, 16)
    }

    private func compact(_ n: Int) -> String {
        if n >= 1_000 { return String(format: "%.1fك", Double(n) / 1_000) }
        return "\(n)"
    }
}
