import SwiftUI

struct LoginView: View {
    @EnvironmentObject private var session: SessionStore
    @Environment(\.dismiss) private var dismiss

    @State private var email: String = ""
    @State private var password: String = ""
    @State private var errorMessage: String?
    @State private var isSubmitting = false

    var body: some View {
        ScrollView {
            VStack(spacing: 18) {
                VStack(spacing: 6) {
                    Image(systemName: "newspaper.fill")
                        .font(.system(size: 44))
                        .foregroundColor(.brand)
                    Text("تسجيل الدخول")
                        .font(.system(size: 22, weight: .bold))
                    Text("تابع آخر الأخبار من مصادر متعددة بلغة عربية")
                        .font(.subheadline)
                        .foregroundColor(.secondary)
                        .multilineTextAlignment(.center)
                }
                .padding(.top, 32)

                VStack(spacing: 12) {
                    LabeledField(title: "البريد الإلكتروني",
                                 text: $email,
                                 keyboard: .emailAddress,
                                 content: .emailAddress)
                    LabeledField(title: "كلمة المرور",
                                 text: $password,
                                 isSecure: true,
                                 content: .password)
                }

                if let err = errorMessage {
                    Text(err).foregroundColor(.red).font(.subheadline)
                }

                Button {
                    Task { await submit() }
                } label: {
                    Group {
                        if isSubmitting { ProgressView().tint(.white) }
                        else { Text("تسجيل الدخول").bold() }
                    }
                    .frame(maxWidth: .infinity).padding(.vertical, 14)
                    .background(canSubmit ? Color.brand : Color.secondary)
                    .foregroundColor(.white)
                    .clipShape(RoundedRectangle(cornerRadius: 12))
                }
                .disabled(!canSubmit || isSubmitting)

                NavigationLink("ليس لديك حساب؟ سجّل الآن", destination: RegisterView())
                    .font(.system(size: 14, weight: .semibold))
                    .foregroundColor(.brand)

                Spacer(minLength: 40)
            }
            .padding(.horizontal, 20)
        }
        .navigationTitle("الدخول")
        .navigationBarTitleDisplayMode(.inline)
    }

    private var canSubmit: Bool {
        email.contains("@") && password.count >= 6
    }

    private func submit() async {
        isSubmitting = true; errorMessage = nil
        defer { isSubmitting = false }
        do {
            try await session.signIn(email: email, password: password)
            dismiss()
        } catch {
            errorMessage = (error as? APIError)?.errorDescription ?? error.localizedDescription
        }
    }
}

struct LabeledField: View {
    let title: String
    @Binding var text: String
    var keyboard: UIKeyboardType = .default
    var isSecure: Bool = false
    var content: UITextContentType? = nil

    var body: some View {
        VStack(alignment: .leading, spacing: 6) {
            Text(title).font(.system(size: 13, weight: .semibold)).foregroundColor(.secondary)
            Group {
                if isSecure {
                    SecureField("", text: $text)
                } else {
                    TextField("", text: $text)
                        .keyboardType(keyboard)
                        .autocapitalization(.none)
                        .disableAutocorrection(true)
                }
            }
            .textContentType(content)
            .padding(12)
            .background(Color.secondary.opacity(0.1))
            .clipShape(RoundedRectangle(cornerRadius: 10))
        }
    }
}

struct LoginPrompt: View {
    let message: String
    var body: some View {
        VStack(spacing: 14) {
            Image(systemName: "person.crop.circle.badge.questionmark")
                .font(.system(size: 42)).foregroundColor(.secondary)
            Text(message).multilineTextAlignment(.center)
                .foregroundColor(.secondary).padding(.horizontal, 32)
            NavigationLink(destination: LoginView()) {
                Text("تسجيل الدخول")
                    .font(.system(size: 15, weight: .semibold))
                    .padding(.horizontal, 20).padding(.vertical, 10)
                    .background(Color.brand).foregroundColor(.white)
                    .clipShape(Capsule())
            }
        }
        .frame(maxWidth: .infinity).padding(.top, 60)
    }
}
