import SwiftUI

@MainActor
final class ReelsViewModel: ObservableObject {
    @Published var items: [Reel] = []
    @Published var isLoading = false
    @Published var errorMessage: String?

    func load() async {
        isLoading = true; defer { isLoading = false }
        do {
            items = try await Endpoints.reels(limit: 20).items
        } catch {
            errorMessage = (error as? APIError)?.errorDescription ?? error.localizedDescription
        }
    }
}

struct ReelsView: View {
    @StateObject private var vm = ReelsViewModel()
    private let columns = [GridItem(.flexible(), spacing: 10), GridItem(.flexible(), spacing: 10)]

    var body: some View {
        ScrollView {
            if vm.isLoading && vm.items.isEmpty {
                ProgressView().padding(.top, 60)
            } else if vm.items.isEmpty {
                EmptyStateView(icon: "play.rectangle", title: "لا توجد ريلز متاحة حالياً")
                    .padding(.top, 60)
            } else {
                LazyVGrid(columns: columns, spacing: 10) {
                    ForEach(vm.items) { reel in
                        ReelCard(reel: reel)
                    }
                }
                .padding(16)
            }
        }
        .refreshable { await vm.load() }
        .task { if vm.items.isEmpty { await vm.load() } }
    }
}

struct ReelCard: View {
    let reel: Reel
    var body: some View {
        Link(destination: URL(string: reel.instagramURL) ?? URL(string: "https://instagram.com")!) {
            VStack(alignment: .trailing, spacing: 6) {
                ZStack {
                    Rectangle()
                        .fill(LinearGradient(colors: [.purple, .pink, .orange],
                                             startPoint: .topLeading, endPoint: .bottomTrailing))
                        .aspectRatio(9/16, contentMode: .fit)
                    VStack {
                        Spacer()
                        Image(systemName: "play.circle.fill")
                            .font(.system(size: 40))
                            .foregroundColor(.white)
                            .shadow(radius: 6)
                        Spacer()
                        HStack(spacing: 4) {
                            Image(systemName: "camera.fill")
                                .font(.caption)
                            Text("Instagram").font(.caption.bold())
                        }
                        .foregroundColor(.white)
                        .padding(8)
                    }
                }
                .clipShape(RoundedRectangle(cornerRadius: 12))

                if let source = reel.source {
                    HStack(spacing: 4) {
                        Text("@\(source.username)")
                            .font(.caption2.bold())
                        Spacer()
                    }
                    .foregroundColor(.secondary)
                }
                if let caption = reel.caption, !caption.isEmpty {
                    Text(caption)
                        .font(.caption2)
                        .lineLimit(2)
                        .multilineTextAlignment(.trailing)
                        .frame(maxWidth: .infinity, alignment: .trailing)
                }
            }
        }
        .buttonStyle(.plain)
    }
}
