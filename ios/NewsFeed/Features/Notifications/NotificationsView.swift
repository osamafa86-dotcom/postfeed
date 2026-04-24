import SwiftUI

struct NotificationsView: View {
    @EnvironmentObject private var session: SessionStore
    @State private var items: [AppNotification] = []
    @State private var unread: Int = 0
    @State private var isLoading = false

    var body: some View {
        Group {
            if !session.isLoggedIn {
                LoginPrompt(message: "سجّل الدخول لمتابعة إشعاراتك")
            } else if isLoading && items.isEmpty {
                ProgressView().padding(.top, 40)
            } else if items.isEmpty {
                VStack(spacing: 10) {
                    Image(systemName: "bell.slash").font(.system(size: 40)).foregroundColor(.secondary)
                    Text("لا توجد إشعارات حالياً")
                        .foregroundColor(.secondary)
                }
                .frame(maxWidth: .infinity).padding(.top, 60)
            } else {
                List {
                    ForEach(items) { n in
                        Group {
                            if let id = n.articleId {
                                NavigationLink(value: id) { row(n) }
                            } else {
                                row(n)
                            }
                        }
                    }
                }
                .listStyle(.plain)
            }
        }
        .navigationTitle("الإشعارات")
        .navigationBarTitleDisplayMode(.inline)
        .toolbar {
            if !items.isEmpty && unread > 0 {
                ToolbarItem(placement: .topBarTrailing) {
                    Button("قراءة الكل") {
                        Task { await markAllRead() }
                    }
                    .font(.system(size: 14, weight: .semibold))
                }
            }
        }
        .task { if session.isLoggedIn { await load() } }
        .refreshable { await load() }
        .navigationDestination(for: Int.self) { id in ArticleDetailView(articleId: id) }
    }

    private func row(_ n: AppNotification) -> some View {
        HStack(alignment: .top, spacing: 10) {
            Text(n.icon ?? "🔔").font(.system(size: 24))
            VStack(alignment: .leading, spacing: 4) {
                HStack {
                    Text(n.title).font(.system(size: 15, weight: .semibold))
                    Spacer()
                    if !n.isRead {
                        Circle().fill(Color.brand).frame(width: 8, height: 8)
                    }
                }
                if let body = n.body {
                    Text(body).font(.system(size: 14)).foregroundColor(.secondary)
                }
                Text(RelativeTime.arabic(from: n.createdAt))
                    .font(.caption).foregroundColor(.secondary)
            }
        }
        .padding(.vertical, 6)
    }

    private func load() async {
        isLoading = true; defer { isLoading = false }
        do {
            let resp = try await Endpoints.notifications()
            items = resp.items; unread = resp.unread
        } catch { }
    }

    private func markAllRead() async {
        _ = try? await Endpoints.markNotificationsRead(id: nil)
        await load()
    }
}
