import SwiftUI

/// Placeholder for sections (e.g. Map, Timelines) whose dedicated API
/// endpoints aren't exposed yet. Keeps the nav reachable without breaking
/// the app, and drops users to the web version as a fallback.
struct ComingSoonView: View {
    let title: String
    let icon: String

    var body: some View {
        VStack(spacing: 16) {
            Image(systemName: icon)
                .font(.system(size: 56))
                .foregroundColor(.brand.opacity(0.7))
            Text(title)
                .font(.title2.bold())
            Text("هذا القسم قيد التطوير في التطبيق. يمكنك تصفحه الآن على الموقع.")
                .multilineTextAlignment(.center)
                .foregroundColor(.secondary)
                .padding(.horizontal, 32)
            Link(destination: URL(string: APIConfig.webBaseURL.absoluteString)!) {
                Label("فتح على الموقع", systemImage: "safari")
                    .font(.system(size: 15, weight: .semibold))
                    .padding(.horizontal, 20).padding(.vertical, 10)
                    .background(Color.brand).foregroundColor(.white)
                    .clipShape(Capsule())
            }
        }
        .frame(maxWidth: .infinity, maxHeight: .infinity)
    }
}
