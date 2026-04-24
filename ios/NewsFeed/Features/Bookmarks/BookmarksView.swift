import SwiftUI

struct BookmarksView: View {
    @EnvironmentObject private var session: SessionStore
    @State private var items: [Article] = []
    @State private var isLoading = false
    @State private var nextCursor: Int?
    @State private var errorMessage: String?

    var body: some View {
        Group {
            if !session.isLoggedIn {
                LoginPrompt(message: "سجّل الدخول لعرض المقالات المحفوظة")
            } else {
                content
            }
        }
        .navigationTitle("المحفوظات")
        .navigationBarTitleDisplayMode(.inline)
        .task { if session.isLoggedIn && items.isEmpty { await load(reset: true) } }
        .refreshable { await load(reset: true) }
        .navigationDestination(for: Int.self) { id in ArticleDetailView(articleId: id) }
    }

    @ViewBuilder
    private var content: some View {
        if isLoading && items.isEmpty {
            ProgressView().padding(.top, 40)
        } else if items.isEmpty {
            VStack(spacing: 10) {
                Image(systemName: "bookmark").font(.system(size: 40)).foregroundColor(.secondary)
                Text("لم تحفظ أي مقالات بعد")
                    .foregroundColor(.secondary)
            }
            .frame(maxWidth: .infinity).padding(.top, 60)
        } else {
            ScrollView {
                LazyVStack(spacing: 12) {
                    ForEach(items) { a in
                        NavigationLink(value: a.id) {
                            ArticleCardView(article: a)
                        }
                        .buttonStyle(.plain)
                        .swipeActions(edge: .trailing) {
                            Button(role: .destructive) {
                                Task { await unbookmark(a) }
                            } label: {
                                Label("حذف", systemImage: "trash")
                            }
                        }
                    }
                }
                .padding(.vertical, 12)
            }
        }
    }

    private func load(reset: Bool) async {
        isLoading = true; defer { isLoading = false }
        do {
            let resp = try await Endpoints.bookmarks(beforeId: reset ? nil : nextCursor, limit: 20)
            if reset { items = resp.items } else { items.append(contentsOf: resp.items) }
            nextCursor = resp.nextCursor
        } catch {
            errorMessage = (error as? APIError)?.errorDescription ?? error.localizedDescription
        }
    }

    private func unbookmark(_ a: Article) async {
        do {
            _ = try await Endpoints.toggleBookmark(articleId: a.id)
            items.removeAll { $0.id == a.id }
        } catch { /* silently */ }
    }
}
