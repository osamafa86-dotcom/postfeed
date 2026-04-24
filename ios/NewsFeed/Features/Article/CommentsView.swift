import SwiftUI

struct CommentsView: View {
    let articleId: Int

    @EnvironmentObject private var session: SessionStore
    @Environment(\.dismiss) private var dismiss
    @State private var comments: [Comment] = []
    @State private var text: String = ""
    @State private var isLoading = false
    @State private var isSending = false
    @State private var errorMessage: String?
    @State private var reportTarget: Int?

    var body: some View {
        VStack(spacing: 0) {
            ScrollView {
                LazyVStack(spacing: 12) {
                    if isLoading && comments.isEmpty {
                        ProgressView().padding(.top, 40)
                    } else {
                        ForEach(comments) { c in
                            CommentRow(comment: c) {
                                reportTarget = c.id
                            }
                        }
                        if comments.isEmpty && !isLoading {
                            Text("لا توجد تعليقات بعد. كن أول من يعلّق.")
                                .foregroundColor(.secondary)
                                .padding(.top, 40)
                        }
                    }
                }
                .padding()
            }

            Divider()

            if session.isLoggedIn {
                composer
            } else {
                HStack {
                    Text("سجّل الدخول لتتمكن من التعليق")
                        .foregroundColor(.secondary)
                    Spacer()
                    NavigationLink("تسجيل الدخول", destination: LoginView())
                        .font(.system(size: 14, weight: .semibold))
                        .foregroundColor(.brand)
                }
                .padding()
            }
        }
        .navigationTitle("التعليقات")
        .navigationBarTitleDisplayMode(.inline)
        .toolbar {
            ToolbarItem(placement: .topBarLeading) {
                Button("إغلاق") { dismiss() }
            }
        }
        .task { await load() }
        .alert("خطأ", isPresented: .constant(errorMessage != nil),
               presenting: errorMessage) { _ in
            Button("حسناً") { errorMessage = nil }
        } message: { msg in Text(msg) }
        .sheet(item: Binding(
            get: { reportTarget.map { IntID(value: $0) } },
            set: { reportTarget = $0?.value }
        )) { target in
            ReportSheet(kind: "comment", targetId: target.value)
        }
    }

    private var composer: some View {
        HStack(spacing: 8) {
            TextField("اكتب تعليقك…", text: $text, axis: .vertical)
                .lineLimit(1...4)
                .padding(10)
                .background(Color.secondary.opacity(0.12))
                .clipShape(RoundedRectangle(cornerRadius: 12))
            Button {
                Task { await send() }
            } label: {
                Group {
                    if isSending { ProgressView().tint(.white) }
                    else { Image(systemName: "paperplane.fill") }
                }
                .frame(width: 36, height: 36)
                .background(canSend ? Color.brand : Color.secondary)
                .foregroundColor(.white)
                .clipShape(Circle())
            }
            .disabled(!canSend || isSending)
        }
        .padding()
    }

    private var canSend: Bool {
        text.trimmingCharacters(in: .whitespacesAndNewlines).count >= 2
    }

    private func load() async {
        isLoading = true
        defer { isLoading = false }
        do {
            comments = try await Endpoints.comments(articleId: articleId).items
        } catch {
            errorMessage = (error as? APIError)?.errorDescription ?? error.localizedDescription
        }
    }

    private func send() async {
        isSending = true
        defer { isSending = false }
        do {
            let resp = try await Endpoints.addComment(articleId: articleId,
                                                      body: text.trimmingCharacters(in: .whitespacesAndNewlines))
            comments.insert(resp.comment, at: 0)
            text = ""
        } catch {
            errorMessage = (error as? APIError)?.errorDescription ?? error.localizedDescription
        }
    }
}

struct CommentRow: View {
    let comment: Comment
    let onReport: () -> Void

    var body: some View {
        HStack(alignment: .top, spacing: 10) {
            ZStack {
                Circle().fill(Color.brand.opacity(0.15)).frame(width: 36, height: 36)
                Text(comment.avatarLetter)
                    .font(.system(size: 15, weight: .bold))
                    .foregroundColor(.brand)
            }
            VStack(alignment: .leading, spacing: 4) {
                HStack {
                    Text(comment.userName).font(.system(size: 14, weight: .semibold))
                    Spacer()
                    Text(RelativeTime.arabic(from: comment.createdAt))
                        .font(.caption).foregroundColor(.secondary)
                }
                Text(comment.body).font(.system(size: 15))
                HStack {
                    Spacer()
                    Button(action: onReport) {
                        Label("إبلاغ", systemImage: "flag")
                            .font(.caption)
                            .foregroundColor(.secondary)
                    }
                }
            }
        }
        .padding(12)
        .background(RoundedRectangle(cornerRadius: 12).fill(Color.secondary.opacity(0.06)))
    }
}

struct IntID: Identifiable { let value: Int; var id: Int { value } }
