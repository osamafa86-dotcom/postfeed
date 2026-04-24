import SwiftUI

struct ErrorState: View {
    let message: String
    let retry: () -> Void
    var body: some View {
        VStack(spacing: 12) {
            Image(systemName: "wifi.exclamationmark")
                .font(.system(size: 40))
                .foregroundColor(.secondary)
            Text(message)
                .multilineTextAlignment(.center)
                .foregroundColor(.secondary)
            Button("إعادة المحاولة", action: retry)
                .buttonStyle(.borderedProminent)
                .tint(.brand)
        }
        .padding(32)
    }
}
