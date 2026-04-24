import SwiftUI

struct SearchView: View {
    @State private var query: String = ""
    @State private var items: [Article] = []
    @State private var isSearching = false
    @State private var errorMessage: String?
    @State private var searchTask: Task<Void, Never>?

    var body: some View {
        ScrollView {
            LazyVStack(spacing: 12) {
                if isSearching && items.isEmpty {
                    ProgressView().padding(.top, 40)
                } else if items.isEmpty && !query.isEmpty && !isSearching {
                    VStack(spacing: 10) {
                        Image(systemName: "magnifyingglass").font(.system(size: 40))
                            .foregroundColor(.secondary)
                        Text("لا نتائج لـ “\(query)”")
                            .foregroundColor(.secondary)
                    }
                    .padding(.top, 60)
                } else if query.isEmpty {
                    EmptySearchPrompt()
                        .padding(.top, 60)
                } else {
                    ForEach(items) { article in
                        NavigationLink(value: article.id) {
                            ArticleCardView(article: article)
                        }
                        .buttonStyle(.plain)
                    }
                }
            }
            .padding(.vertical, 12)
        }
        .navigationTitle("البحث")
        .navigationBarTitleDisplayMode(.inline)
        .searchable(text: $query, prompt: "ابحث في الأخبار…")
        .onChange(of: query) { newValue in
            searchTask?.cancel()
            guard newValue.count >= 2 else {
                items = []
                return
            }
            searchTask = Task {
                try? await Task.sleep(nanoseconds: 350_000_000) // 350 ms debounce
                if Task.isCancelled { return }
                await performSearch(newValue)
            }
        }
        .navigationDestination(for: Int.self) { id in
            ArticleDetailView(articleId: id)
        }
    }

    private func performSearch(_ q: String) async {
        isSearching = true
        defer { isSearching = false }
        do {
            items = try await Endpoints.search(q).items
        } catch {
            errorMessage = (error as? APIError)?.errorDescription ?? error.localizedDescription
        }
    }
}

struct EmptySearchPrompt: View {
    var body: some View {
        VStack(spacing: 10) {
            Image(systemName: "newspaper").font(.system(size: 40)).foregroundColor(.secondary)
            Text("اكتب كلمتين على الأقل للبحث في آخر الأخبار")
                .multilineTextAlignment(.center)
                .foregroundColor(.secondary)
                .padding(.horizontal, 32)
        }
    }
}
