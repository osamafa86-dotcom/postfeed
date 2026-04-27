#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
# setup_ios.sh — One-time iOS Simulator setup for فيد نيوز
# Run from repo root: bash setup_ios.sh
# ─────────────────────────────────────────────────────────────────────────────
set -e

REPO="$(cd "$(dirname "$0")" && pwd)"
APP="$REPO/app"

echo ""
echo "══════════════════════════════════════════"
echo "  فيد نيوز — iOS Simulator Setup"
echo "══════════════════════════════════════════"
echo ""

# ── 1. Boot simulator if needed ───────────────────────────────────────────────
echo "[1/6] Booting iPhone Simulator..."
BOOTED=$(xcrun simctl list devices | grep "Booted" | head -1)
if [ -z "$BOOTED" ]; then
    xcrun simctl boot "iPhone 17 Pro" 2>/dev/null || \
    xcrun simctl boot "iPhone 16 Pro" 2>/dev/null || \
    xcrun simctl boot "iPhone 15 Pro" 2>/dev/null || \
    xcrun simctl list devices available | grep -i "iphone" | head -1 | \
        grep -oP '\(.*?\)' | head -1 | tr -d '()' | xargs xcrun simctl boot
    echo "  simulator booted"
else
    echo "  already running: $BOOTED"
fi

# ── 2. Stub GoogleService-Info.plist ─────────────────────────────────────────
echo ""
echo "[2/6] GoogleService-Info.plist..."
GSI="$APP/ios/Runner/GoogleService-Info.plist"
if [ ! -f "$GSI" ]; then
cat > "$GSI" << 'PLIST'
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
	<key>CLIENT_ID</key>
	<string>000000000000-placeholder.apps.googleusercontent.com</string>
	<key>REVERSED_CLIENT_ID</key>
	<string>com.googleusercontent.apps.000000000000-placeholder</string>
	<key>API_KEY</key>
	<string>AIzaPlaceholderKey000000000000000000000</string>
	<key>GCL_VERSION</key>
	<string>4.0.0</string>
	<key>BUNDLE_ID</key>
	<string>net.feedsnews.app</string>
	<key>PROJECT_ID</key>
	<string>feedsnews-dev</string>
	<key>STORAGE_BUCKET</key>
	<string>feedsnews-dev.appspot.com</string>
	<key>IS_ADS_ENABLED</key>
	<false/>
	<key>IS_ANALYTICS_ENABLED</key>
	<false/>
	<key>IS_APPINVITE_ENABLED</key>
	<true/>
	<key>IS_GCM_ENABLED</key>
	<true/>
	<key>IS_SIGNIN_ENABLED</key>
	<true/>
	<key>GOOGLE_APP_ID</key>
	<string>1:000000000000:ios:0000000000000000000000</string>
</dict>
</plist>
PLIST
    echo "  created (dev stub)"
else
    echo "  exists"
fi

# ── 3. Fix project.pbxproj — disable code signing for simulator ───────────────
echo ""
echo "[3/6] Fixing Xcode code-signing..."
PBXPROJ="$APP/ios/Runner.xcodeproj/project.pbxproj"
if [ -f "$PBXPROJ" ]; then
    export PBXPROJ
    python3 << 'PYEOF'
import sys, os
f = os.environ['PBXPROJ']
with open(f) as fp: c = fp.read()
if 'CODE_SIGNING_ALLOWED = NO' in c:
    print('  project.pbxproj: already fixed')
    sys.exit()
c = c.replace(
    'CODE_SIGN_STYLE = Automatic;',
    'CODE_SIGN_STYLE = Automatic;\n\t\t\t\tCODE_SIGNING_ALLOWED = NO;'
)
with open(f, 'w') as fp: fp.write(c)
print('  project.pbxproj: CODE_SIGNING_ALLOWED = NO added')
PYEOF
else
    echo "  project.pbxproj not found yet — flutter will create it"
fi

# ── 4. Fix Podfile — disable code signing + fix deployment targets ────────────
echo ""
echo "[4/6] Fixing Podfile..."
PODFILE="$APP/ios/Podfile"
if [ -f "$PODFILE" ]; then
    export PODFILE
    python3 << 'PYEOF'
import sys, os
f = os.environ['PODFILE']
with open(f) as fp: c = fp.read()
if 'CODE_SIGNING_ALLOWED' in c:
    print('  Podfile: already fixed')
    sys.exit()
old = '    flutter_additional_ios_build_settings(target)\n  end\nend'
new = ('    flutter_additional_ios_build_settings(target)\n'
       "    target.build_configurations.each do |config|\n"
       "      config.build_settings['CODE_SIGNING_ALLOWED'] = 'NO'\n"
       "      config.build_settings['CODE_SIGNING_REQUIRED'] = 'NO'\n"
       "      config.build_settings['CODE_SIGN_IDENTITY'] = ''\n"
       "    end\n"
       "  end\nend")
if old in c:
    with open(f, 'w') as fp: fp.write(c.replace(old, new))
    print('  Podfile: CODE_SIGNING_ALLOWED = NO added')
else:
    print('  Podfile: unexpected format — adding at end')
    c = c.rstrip()
    if not c.endswith('end'):
        print('  WARNING: Podfile format unexpected, check manually')
    else:
        print('  Already has correct structure')
PYEOF
else
    echo "  Podfile not found — run from repo root"
    exit 1
fi

# ── 5. Flutter clean + pub get ────────────────────────────────────────────────
echo ""
echo "[5/6] flutter clean + pub get..."
cd "$APP"
# Strip macOS extended attributes (Finder info, quarantine, etc.) — codesign
# refuses frameworks that carry "resource fork / Finder information" detritus.
# We have to clean the Flutter SDK and pub-cache too, otherwise Flutter.framework
# is copied into build/ with xattrs intact every time and codesign fails again.
FLUTTER_ROOT="$(dirname "$(dirname "$(command -v flutter)")")"
echo "  stripping xattrs from $FLUTTER_ROOT"
xattr -cr "$FLUTTER_ROOT" 2>/dev/null || true
xattr -cr "$HOME/.pub-cache" 2>/dev/null || true
xattr -cr "$REPO" 2>/dev/null || true
flutter clean
flutter pub get

# ── 6. Launch ─────────────────────────────────────────────────────────────────
echo ""
echo "[6/6] Launching app on Simulator..."
echo "      (first build ~5 min, coffee time ☕)"
echo ""
flutter run --dart-define=API_BASE=https://feedsnews.net/api/v1
