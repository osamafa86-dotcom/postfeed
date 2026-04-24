import SwiftUI

@MainActor
final class TelegramViewModel: ObservableObject {
    @Published var messages: [TelegramMessage] = []
    @Published var sources: [TelegramSource] = []
    @Published var selectedSourceId: Int? = nil
    @Published var isLoading = false
    @Published var errorMessage: String?

    func load() async {
        isLoading = true; defer { isLoading = false }
        do {
            let resp = try await Endpoints.telegram(limit: 30, sourceId: selectedSourceId)
            self.messages = resp.items
            if self.sources.isEmpty { self.sources = resp.sources }
        } catch {
            errorMessage = (error as? APIError)?.errorDescription ?? error.localizedDescription
        }
    }
}

struct TelegramView: View {
    @StateObject private var vm = TelegramViewModel()
    private let columns = [GridItem(.flexible(), spacing: 10), GridItem(.flexible(), spacing: 10)]

    var body: some View {
        VStack(spacing: 0) {
            if !vm.sources.isEmpty {
                ScrollView(.horizontal, showsIndicators: false) {
                    HStack(spacing: 8) {
                        chip(title: "الكل", id: nil)
                        ForEach(vm.sources) { src in
                            chip(title: src.name, id: src.id)
                        }
                    }
                    .padding(.horizontal, 16)
                    .padding(.vertical, 8)
                }
            }

            ScrollView {
                if vm.isLoading && vm.messages.isEmpty {
                    ProgressView().padding(.top, 60)
                } else if vm.messages.isEmpty, let err = vm.errorMessage {
                    ErrorState(message: err) { Task { await vm.load() } }.padding(.top, 40)
                } else if vm.messages.isEmpty {
                    EmptyStateView(icon: "paperplane", title: "لا توجد رسائل حالياً")
                        .padding(.top, 60)
                } else {
                    LazyVGrid(columns: columns, spacing: 10) {
                        ForEach(vm.messages) { msg in
                            TelegramCard(message: msg)
                        }
                    }
                    .padding(.horizontal, 16)
                    .padding(.vertical, 12)
                }
            }
            .refreshable { await vm.load() }
        }
        .task { if vm.messages.isEmpty { await vm.load() } }
    }

    @ViewBuilder
    private func chip(title: String, id: Int?) -> some View {
        let selected = vm.selectedSourceId == id
        Button {
            vm.selectedSourceId = id
            Task { await vm.load() }
        } label: {
            Text(title)
                .font(.system(size: 13, weight: selected ? .bold : .medium))
                .padding(.horizontal, 12).padding(.vertical, 6)
                .background(selected ? Color.brand : Color.secondary.opacity(0.1))
                .foregroundColor(selected ? .white : .primary)
                .clipShape(Capsule())
        }
    }
}

struct TelegramCard: View {
    let message: TelegramMessage
    var body: some View {
        VStack(alignment: .trailing, spacing: 8) {
            HStack(spacing: 6) {
                ZStack {
                    Circle().fill(Color.brand.opacity(0.15)).frame(width: 24, height: 24)
                    Text(String(message.source.name.prefix(1)))
                        .font(.system(size: 11, weight: .bold))
                        .foregroundColor(.brand)
                }
                Text(message.source.name)
                    .font(.caption.bold())
                    .lineLimit(1)
                Spacer()
            }
            if let img = message.imageURL {
                RemoteImage(url: img)
                    .aspectRatio(1, contentMode: .fill)
                    .frame(maxWidth: .infinity)
                    .clipped()
                    .clipShape(RoundedRectangle(cornerRadius: 8))
            }
            Text(message.text)
                .font(.system(size: 12))
                .lineLimit(6)
                .multilineTextAlignment(.trailing)
                .frame(maxWidth: .infinity, alignment: .trailing)
            Text(RelativeTime.arabic(from: message.postedAt))
                .font(.caption2).foregroundColor(.secondary)
            if let urlStr = message.postURL, let url = URL(string: urlStr) {
                Link(destination: url) {
                    HStack(spacing: 4) {
                        Image(systemName: "arrow.up.forward.square")
                        Text("فتح في تلغرام")
                    }
                    .font(.caption2.bold())
                    .foregroundColor(.brand)
                }
            }
        }
        .padding(10)
        .background(RoundedRectangle(cornerRadius: 12).fill(Color(.systemBackground))
            .shadow(color: .black.opacity(0.04), radius: 4, y: 2))
    }
}

struct EmptyStateView: View {
    let icon: String
    let title: String
    var body: some View {
        VStack(spacing: 10) {
            Image(systemName: icon).font(.system(size: 40)).foregroundColor(.secondary)
            Text(title).foregroundColor(.secondary).multilineTextAlignment(.center)
        }
        .padding(30)
    }
}
