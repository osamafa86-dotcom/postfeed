import UIKit

enum UIDeviceInfo {
    static var deviceName: String {
        UIDevice.current.name
    }
    static var systemVersion: String {
        UIDevice.current.systemVersion
    }
    static var model: String {
        var sys = utsname()
        uname(&sys)
        let mirror = Mirror(reflecting: sys.machine)
        let id = mirror.children.reduce("") { acc, el in
            guard let v = el.value as? Int8, v != 0 else { return acc }
            return acc + String(UnicodeScalar(UInt8(v)))
        }
        return id.isEmpty ? UIDevice.current.model : id
    }
}
