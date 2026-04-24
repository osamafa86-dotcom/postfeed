import SwiftUI

struct RegisterView: View {
    @EnvironmentObject private var session: SessionStore
    @Environment(\.dismiss) private var dismiss

    @State private var name: String = ""
    @State private var email: String = ""
    @State private var password: String = ""
    @State private var username: String = ""
    @State private var errorMessage: String?
    @State private var isSubmitting = false
    @State private var acceptedTerms = false

    var body: some View {
        ScrollView {
            VStack(spacing: 16) {
                Text("إنشاء حساب جديد")
                    .font(.system(size: 22, weight: .bold))
                    .padding(.top, 24)

                VStack(spacing: 10) {
                    LabeledField(title: "الاسم الكامل", text: $name, content: .name)
                    LabeledField(title: "البريد الإلكتروني", text: $email,
                                 keyboard: .emailAddress, content: .emailAddress)
                    LabeledField(title: "اسم المستخدم (اختياري)", text: $username)
                    LabeledField(title: "كلمة المرور (8 أحرف على الأقل)",
                                 text: $password, isSecure: true, content: .newPassword)
                }

                termsBlock

                if let err = errorMessage {
                    Text(err).foregroundColor(.red).font(.subheadline)
                }

                Button {
                    Task { await submit() }
                } label: {
                    Group {
                        if isSubmitting { ProgressView().tint(.white) }
                        else { Text("إنشاء الحساب").bold() }
                    }
                    .frame(maxWidth: .infinity).padding(.vertical, 14)
                    .background(canSubmit ? Color.brand : Color.secondary)
                    .foregroundColor(.white)
                    .clipShape(RoundedRectangle(cornerRadius: 12))
                }
                .disabled(!canSubmit || isSubmitting)

                Spacer(minLength: 40)
            }
            .padding(.horizontal, 20)
        }
        .navigationTitle("تسجيل")
        .navigationBarTitleDisplayMode(.inline)
    }

    private var termsBlock: some View {
        VStack(alignment: .leading, spacing: 6) {
            Toggle(isOn: $acceptedTerms) {
                Text("أوافق على شروط الاستخدام وسياسة الخصوصية")
                    .font(.system(size: 13))
            }
            HStack(spacing: 12) {
                Link("شروط الاستخدام",
                     destination: URL(string: "\(APIConfig.webBaseURL.absoluteString)/about.php")!)
                Link("سياسة الخصوصية",
                     destination: URL(string: "\(APIConfig.webBaseURL.absoluteString)/privacy.php")!)
            }
            .font(.system(size: 12, weight: .semibold))
            .foregroundColor(.brand)
        }
    }

    private var canSubmit: Bool {
        acceptedTerms && name.count >= 2 && email.contains("@") && password.count >= 8
    }

    private func submit() async {
        isSubmitting = true; errorMessage = nil
        defer { isSubmitting = false }
        do {
            try await session.register(name: name, email: email, password: password,
                                        username: username.isEmpty ? nil : username)
            dismiss()
        } catch {
            errorMessage = (error as? APIError)?.errorDescription ?? error.localizedDescription
        }
    }
}
