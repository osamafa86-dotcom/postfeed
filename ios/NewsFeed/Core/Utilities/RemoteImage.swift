import SwiftUI

/// AsyncImage wrapper with a sensible placeholder and in-memory caching via URLCache.
struct RemoteImage: View {
    let url: String?
    var contentMode: ContentMode = .fill

    var body: some View {
        Group {
            if let s = url, let u = URL(string: s) {
                AsyncImage(url: u, transaction: .init(animation: .easeIn(duration: 0.2))) { phase in
                    switch phase {
                    case .empty:
                        placeholder
                    case .success(let img):
                        img.resizable().aspectRatio(contentMode: contentMode)
                    case .failure:
                        placeholder.overlay(
                            Image(systemName: "photo")
                                .foregroundColor(.secondary)
                        )
                    @unknown default:
                        placeholder
                    }
                }
            } else {
                placeholder
            }
        }
    }

    private var placeholder: some View {
        Rectangle()
            .fill(Color.secondary.opacity(0.12))
    }
}
