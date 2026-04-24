import SwiftUI

struct ReportSheet: View {
    let kind: String
    let targetId: Int

    @Environment(\.dismiss) private var dismiss
    @State private var reason: String = ""
    @State private var selectedPreset: String? = nil
    @State private var isSending = false
    @State private var done = false
    @State private var errorMessage: String?

    private let presets = [
        "محتوى مسيء أو تحرشي",
        "معلومات مضللة أو إخبار كاذب",
        "بريد مزعج أو دعاية",
        "محتوى غير لائق",
        "انتهاك حقوق الملكية",
        "سبب آخر",
    ]

    var body: some View {
        NavigationStack {
            Form {
                Section("سبب الإبلاغ") {
                    ForEach(presets, id: \.self) { p in
                        Button {
                            selectedPreset = p
                            reason = p
                        } label: {
                            HStack {
                                Text(p).foregroundColor(.primary)
                                Spacer()
                                if selectedPreset == p {
                                    Image(systemName: "checkmark").foregroundColor(.brand)
                                }
                            }
                        }
                    }
                }
                Section("تفاصيل إضافية (اختياري)") {
                    TextField("اكتب تفاصيل أكثر…", text: $reason, axis: .vertical)
                        .lineLimit(3...6)
                }
            }
            .navigationTitle("إبلاغ")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .topBarLeading) { Button("إلغاء") { dismiss() } }
                ToolbarItem(placement: .topBarTrailing) {
                    Button {
                        Task { await submit() }
                    } label: {
                        if isSending { ProgressView() } else { Text("إرسال").bold() }
                    }
                    .disabled(reason.count < 3 || isSending)
                }
            }
            .alert("تم", isPresented: $done) {
                Button("حسناً") { dismiss() }
            } message: { Text("شكراً — سنراجع هذا البلاغ قريباً.") }
            .alert("خطأ", isPresented: .constant(errorMessage != nil),
                   presenting: errorMessage) { _ in
                Button("حسناً") { errorMessage = nil }
            } message: { msg in Text(msg) }
        }
    }

    private func submit() async {
        isSending = true
        defer { isSending = false }
        do {
            _ = try await Endpoints.report(kind: kind, targetId: targetId, reason: reason)
            done = true
        } catch {
            errorMessage = (error as? APIError)?.errorDescription ?? error.localizedDescription
        }
    }
}
