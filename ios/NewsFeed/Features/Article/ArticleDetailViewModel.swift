import Foundation

@MainActor
final class ArticleDetailViewModel: ObservableObject {
    @Published var article: Article?
    @Published var related: [Article] = []
    @Published var bookmarked = false
    @Published var reaction: String? = nil
    @Published var likes = 0
    @Published var dislikes = 0
    @Published var isLoading = false
    @Published var errorMessage: String?

    func load(articleId: Int) async {
        isLoading = true; errorMessage = nil
        defer { isLoading = false }
        do {
            let resp = try await Endpoints.article(id: articleId)
            self.article = resp.article
            self.related = resp.related
            self.bookmarked = resp.userState?.bookmarked ?? false
            self.reaction = resp.userState?.reaction
        } catch {
            self.errorMessage = (error as? APIError)?.errorDescription ?? error.localizedDescription
        }
    }

    func toggleBookmark(requireLogin: () -> Bool) async {
        guard let article else { return }
        guard requireLogin() else {
            self.errorMessage = "يرجى تسجيل الدخول لحفظ المقالات"
            return
        }
        do {
            let resp = try await Endpoints.toggleBookmark(articleId: article.id)
            self.bookmarked = resp.saved
        } catch {
            self.errorMessage = (error as? APIError)?.errorDescription ?? error.localizedDescription
        }
    }

    func toggleReaction(_ value: String, requireLogin: () -> Bool) async {
        guard let article else { return }
        guard requireLogin() else {
            self.errorMessage = "يرجى تسجيل الدخول للتفاعل"
            return
        }
        let next: String? = reaction == value ? nil : value
        do {
            let resp = try await Endpoints.react(articleId: article.id, reaction: next)
            self.reaction = resp.reaction
            self.likes = resp.likes
            self.dislikes = resp.dislikes
        } catch {
            self.errorMessage = (error as? APIError)?.errorDescription ?? error.localizedDescription
        }
    }
}
