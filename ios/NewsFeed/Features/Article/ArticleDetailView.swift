import SwiftUI

struct ArticleDetailView: View {
    let articleId: Int

    @StateObject private var vm = ArticleDetailViewModel()
    @EnvironmentObject private var session: SessionStore
    @State private var showShare = false
    @State private var showComments = false
    @State private var showReport = false

    var body: some View {
        ScrollView {
            if let article = vm.article {
                VStack(alignment: .leading, spacing: 14) {
                    RemoteImage(url: article.imageURL)
                        .frame(height: 260)
                        .clipped()

                    VStack(alignment: .leading, spacing: 12) {
                        metaBar(article)

                        Text(article.title)
                            .font(.system(size: 24, weight: .bold))
                            .multilineTextAlignment(.leading)

                        if let excerpt = article.excerpt, !excerpt.isEmpty {
                            Text(excerpt)
                                .font(.system(size: 17))
                                .foregroundColor(.secondary)
                        }

                        actionBar(article)

                        Divider()

                        ArticleBody(html: article.content ?? "")
                            .padding(.top, 4)

                        if let url = article.sourceURL, let u = URL(string: url) {
                            Link(destination: u) {
                                HStack {
                                    Image(systemName: "arrow.up.forward.square")
                                    Text("اقرأ المصدر الأصلي")
                                }
                                .font(.system(size: 15, weight: .semibold))
                                .padding(.vertical, 12)
                                .padding(.horizontal, 18)
                                .background(Color.brand.opacity(0.1))
                                .foregroundColor(.brand)
                                .clipShape(Capsule())
                            }
                        }

                        if !vm.related.isEmpty {
                            Text("أخبار ذات صلة")
                                .font(.headline)
                                .padding(.top, 12)
                            ForEach(vm.related) { a in
                                NavigationLink(value: a.id) {
                                    ArticleCardView(article: a)
                                        .padding(.horizontal, -16)
                                }
                                .buttonStyle(.plain)
                            }
                        }
                    }
                    .padding(16)
                }
            } else if vm.isLoading {
                ProgressView().padding(.top, 60)
            } else if let err = vm.errorMessage {
                ErrorState(message: err) { Task { await vm.load(articleId: articleId) } }
                    .padding(.top, 60)
            }
        }
        .navigationBarTitleDisplayMode(.inline)
        .toolbar {
            ToolbarItem(placement: .topBarTrailing) {
                Menu {
                    Button { showShare = true } label: {
                        Label("مشاركة", systemImage: "square.and.arrow.up")
                    }
                    if session.isLoggedIn {
                        Button(role: .destructive) { showReport = true } label: {
                            Label("الإبلاغ", systemImage: "flag")
                        }
                    }
                } label: {
                    Image(systemName: "ellipsis")
                }
            }
        }
        .task { if vm.article == nil { await vm.load(articleId: articleId) } }
        .sheet(isPresented: $showShare) {
            if let article = vm.article {
                let text = "\(article.title)\n\(shareURL(article))"
                ShareSheet(items: [text])
            }
        }
        .sheet(isPresented: $showComments) {
            if let article = vm.article {
                NavigationStack { CommentsView(articleId: article.id) }
            }
        }
        .sheet(isPresented: $showReport) {
            if let article = vm.article {
                ReportSheet(kind: "article", targetId: article.id)
            }
        }
    }

    private func metaBar(_ article: Article) -> some View {
        HStack(spacing: 10) {
            SourceBadge(source: article.source)
            if let name = article.category.name {
                Text(name)
                    .font(.caption.bold())
                    .padding(.horizontal, 8).padding(.vertical, 4)
                    .background(Color.category(article.category.slug).opacity(0.15))
                    .foregroundColor(Color.category(article.category.slug))
                    .clipShape(Capsule())
            }
            Spacer()
            Text(RelativeTime.arabic(from: article.publishedAt))
                .font(.caption).foregroundColor(.secondary)
        }
    }

    @ViewBuilder
    private func actionBar(_ article: Article) -> some View {
        HStack(spacing: 10) {
            ReactionButton(
                system: vm.reaction == "like" ? "hand.thumbsup.fill" : "hand.thumbsup",
                count: vm.likes, active: vm.reaction == "like"
            ) {
                Task { await vm.toggleReaction("like", requireLogin: showLoginIfNeeded) }
            }
            ReactionButton(
                system: vm.reaction == "dislike" ? "hand.thumbsdown.fill" : "hand.thumbsdown",
                count: vm.dislikes, active: vm.reaction == "dislike"
            ) {
                Task { await vm.toggleReaction("dislike", requireLogin: showLoginIfNeeded) }
            }
            Button {
                showComments = true
            } label: {
                Label("\(article.comments)", systemImage: "bubble.left")
                    .font(.system(size: 14, weight: .semibold))
            }
            .buttonStyle(CardButtonStyle())

            Spacer()

            Button {
                Task { await vm.toggleBookmark(requireLogin: showLoginIfNeeded) }
            } label: {
                Image(systemName: vm.bookmarked ? "bookmark.fill" : "bookmark")
                    .font(.system(size: 18, weight: .semibold))
                    .foregroundColor(vm.bookmarked ? .brand : .primary)
                    .padding(10)
                    .background(Color.secondary.opacity(0.12))
                    .clipShape(Circle())
            }
        }
    }

    private var showLoginIfNeeded: () -> Bool {
        return { session.isLoggedIn }
    }

    private func shareURL(_ article: Article) -> String {
        "\(APIConfig.webBaseURL.absoluteString)/article/\(article.id)/\(article.slug ?? "")"
    }
}

struct ReactionButton: View {
    let system: String
    let count: Int
    let active: Bool
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            HStack(spacing: 6) {
                Image(systemName: system)
                Text("\(count)")
                    .font(.system(size: 14, weight: .semibold))
            }
            .foregroundColor(active ? .brand : .primary)
        }
        .buttonStyle(CardButtonStyle())
    }
}

struct CardButtonStyle: ButtonStyle {
    func makeBody(configuration: Configuration) -> some View {
        configuration.label
            .padding(.horizontal, 12).padding(.vertical, 8)
            .background(Color.secondary.opacity(configuration.isPressed ? 0.25 : 0.12))
            .clipShape(Capsule())
    }
}

/// Renders server-provided HTML using AttributedString.
struct ArticleBody: View {
    let html: String
    @State private var attributed: AttributedString?

    var body: some View {
        Group {
            if let a = attributed {
                Text(a)
                    .textSelection(.enabled)
                    .lineSpacing(6)
            } else {
                Text(stripped)
                    .textSelection(.enabled)
                    .lineSpacing(6)
            }
        }
        .font(.system(size: 17))
        .task(id: html) {
            self.attributed = Self.render(html: html)
        }
    }

    private var stripped: String {
        html.replacingOccurrences(of: "<[^>]+>", with: "", options: .regularExpression)
            .replacingOccurrences(of: "&nbsp;", with: " ")
            .replacingOccurrences(of: "&amp;", with: "&")
    }

    private static func render(html: String) -> AttributedString? {
        guard let data = html.data(using: .utf8) else { return nil }
        let opts: [NSAttributedString.DocumentReadingOptionKey: Any] = [
            .documentType: NSAttributedString.DocumentType.html,
            .characterEncoding: String.Encoding.utf8.rawValue,
        ]
        guard let ns = try? NSAttributedString(data: data, options: opts,
                                               documentAttributes: nil) else { return nil }
        return AttributedString(ns)
    }
}

struct ShareSheet: UIViewControllerRepresentable {
    let items: [Any]
    func makeUIViewController(context: Context) -> UIActivityViewController {
        UIActivityViewController(activityItems: items, applicationActivities: nil)
    }
    func updateUIViewController(_ vc: UIActivityViewController, context: Context) {}
}
