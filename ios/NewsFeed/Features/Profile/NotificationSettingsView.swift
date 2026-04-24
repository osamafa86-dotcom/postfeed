import SwiftUI

struct NotificationSettingsView: View {
    @EnvironmentObject private var session: SessionStore
    @State private var notifyBreaking = true
    @State private var notifyFollowed = true
    @State private var notifyDigest = false
    @State private var isSaving = false

    var body: some View {
        Form {
            Section("أريد إشعاراً عند") {
                Toggle("الأخبار العاجلة", isOn: $notifyBreaking)
                Toggle("تحديثات الفئات/المصادر التي أتابعها", isOn: $notifyFollowed)
                Toggle("الموجز اليومي", isOn: $notifyDigest)
            }
            Section {
                Link(destination: URL(string: UIApplication.openSettingsURLString)!) {
                    Label("إذن إشعارات النظام", systemImage: "gearshape")
                }
            } footer: {
                Text("يمكنك السماح أو إيقاف الإشعارات من إعدادات iOS في أي وقت.")
            }
        }
        .navigationTitle("الإشعارات")
        .navigationBarTitleDisplayMode(.inline)
        .toolbar {
            ToolbarItem(placement: .topBarTrailing) {
                Button {
                    Task { await save() }
                } label: {
                    if isSaving { ProgressView() } else { Text("حفظ") }
                }
                .disabled(isSaving)
            }
        }
        .onAppear {
            if let u = session.user {
                notifyBreaking = u.notifyBreaking
                notifyFollowed = u.notifyFollowed
                notifyDigest = u.notifyDigest
            }
        }
    }

    private func save() async {
        isSaving = true; defer { isSaving = false }
        _ = try? await Endpoints.updateProfile(ProfilePatch(
            notify_breaking: notifyBreaking,
            notify_followed: notifyFollowed,
            notify_digest: notifyDigest
        ))
        await session.refreshProfile()
    }
}
