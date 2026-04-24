import SwiftUI

struct AskView: View {
    @State private var question: String = ""
    @State private var answer: String = ""
    @State private var followUps: [String] = []
    @State private var sources: [AskArticle] = []
    @State private var isLoading = false
    @State private var errorMessage: String?

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 16) {
                header

                TextField("مثال: ما آخر تطورات القضية؟", text: $question, axis: .vertical)
                    .lineLimit(2...4)
                    .padding(12)
                    .background(Color.secondary.opacity(0.1))
                    .clipShape(RoundedRectangle(cornerRadius: 12))

                Button {
                    Task { await ask() }
                } label: {
                    Group {
                        if isLoading {
                            ProgressView().tint(.white)
                        } else {
                            Label("اسأل", systemImage: "sparkles")
                        }
                    }
                    .frame(maxWidth: .infinity)
                    .padding(.vertical, 12)
                    .background(canAsk ? Color.brand : Color.secondary)
                    .foregroundColor(.white)
                    .clipShape(RoundedRectangle(cornerRadius: 12))
                }
                .disabled(!canAsk || isLoading)

                if let err = errorMessage {
                    Text(err).foregroundColor(.red).font(.subheadline)
                }

                if !answer.isEmpty {
                    VStack(alignment: .leading, spacing: 10) {
                        Text("الإجابة")
                            .font(.headline).foregroundColor(.secondary)
                        Text(answer)
                            .textSelection(.enabled)
                    }
                    .padding(14)
                    .background(RoundedRectangle(cornerRadius: 14).fill(Color.secondary.opacity(0.08)))
                }

                if !sources.isEmpty {
                    Text("المصادر").font(.headline).padding(.top, 4)
                    ForEach(sources) { src in
                        NavigationLink(value: src.id) {
                            HStack(spacing: 10) {
                                if let u = src.imageURL {
                                    RemoteImage(url: u).frame(width: 60, height: 60)
                                        .clipShape(RoundedRectangle(cornerRadius: 8))
                                }
                                Text(src.title)
                                    .font(.system(size: 14, weight: .semibold))
                                    .lineLimit(3)
                                    .multilineTextAlignment(.leading)
                                Spacer()
                            }
                            .padding(10)
                            .background(RoundedRectangle(cornerRadius: 12).fill(Color.secondary.opacity(0.06)))
                        }
                        .buttonStyle(.plain)
                    }
                }

                if !followUps.isEmpty {
                    Text("أسئلة متابعة").font(.headline).padding(.top, 8)
                    ForEach(followUps, id: \.self) { f in
                        Button {
                            question = f
                            Task { await ask() }
                        } label: {
                            HStack {
                                Text(f).foregroundColor(.primary).multilineTextAlignment(.leading)
                                Spacer()
                                Image(systemName: "arrow.up.backward.circle").foregroundColor(.brand)
                            }
                            .padding(12)
                            .background(RoundedRectangle(cornerRadius: 12).stroke(Color.brand.opacity(0.3)))
                        }
                    }
                }
            }
            .padding(16)
        }
        .navigationTitle("اسأل الذكاء")
        .navigationBarTitleDisplayMode(.inline)
        .navigationDestination(for: Int.self) { id in ArticleDetailView(articleId: id) }
    }

    private var canAsk: Bool {
        question.trimmingCharacters(in: .whitespacesAndNewlines).count >= 3
    }

    private var header: some View {
        VStack(alignment: .leading, spacing: 8) {
            HStack(spacing: 8) {
                Image(systemName: "sparkles").foregroundColor(.brand)
                Text("بحث ذكي في الأخبار")
                    .font(.headline)
            }
            Text("اسأل سؤالاً وسنجيبك استناداً إلى أحدث الأخبار المنشورة، مع مصادرها.")
                .font(.subheadline)
                .foregroundColor(.secondary)
        }
    }

    private func ask() async {
        let q = question.trimmingCharacters(in: .whitespacesAndNewlines)
        guard q.count >= 3 else { return }
        isLoading = true; errorMessage = nil
        defer { isLoading = false }
        do {
            let resp = try await Endpoints.ask(q)
            answer = resp.answer
            followUps = resp.followUps
            sources = resp.articles
        } catch {
            errorMessage = (error as? APIError)?.errorDescription ?? error.localizedDescription
        }
    }
}
