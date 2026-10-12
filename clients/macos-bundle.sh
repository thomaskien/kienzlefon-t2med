#!/bin/sh
# Finish an osacompile bundle before installing or registering it.
prepare_macos_bundle() (
    set -eu
    bundle=$1
    helper=$2
    plist="$bundle/Contents/Info.plist"
    mkdir -p -- "$bundle/Contents/Resources"
    install -m 0755 "$helper" "$bundle/Contents/Resources/kienzlefon_call.py"
    # osacompile may omit the identifier entirely on a fresh applet.
    if /usr/libexec/PlistBuddy -c 'Print :CFBundleIdentifier' "$plist" >/dev/null 2>&1; then
        /usr/libexec/PlistBuddy -c 'Set :CFBundleIdentifier de.kienzlefon.t2med' "$plist"
    else
        /usr/libexec/PlistBuddy -c 'Add :CFBundleIdentifier string de.kienzlefon.t2med' "$plist"
    fi
    /usr/libexec/PlistBuddy -c 'Delete :CFBundleURLTypes' "$plist" >/dev/null 2>&1 || true
    /usr/libexec/PlistBuddy -c 'Add :CFBundleURLTypes array' "$plist"
    /usr/libexec/PlistBuddy -c 'Add :CFBundleURLTypes:0 dict' "$plist"
    /usr/libexec/PlistBuddy -c 'Add :CFBundleURLTypes:0:CFBundleURLName string de.kienzlefon.t2med.call' "$plist"
    /usr/libexec/PlistBuddy -c 'Add :CFBundleURLTypes:0:CFBundleURLSchemes array' "$plist"
    /usr/libexec/PlistBuddy -c 'Add :CFBundleURLTypes:0:CFBundleURLSchemes:0 string tel' "$plist"
    /usr/libexec/PlistBuddy -c 'Add :CFBundleURLTypes:0:CFBundleURLSchemes:1 string callto' "$plist"
    # Adding the helper and changing Info.plist invalidates osacompile's signature.
    /usr/bin/codesign --force --sign - "$bundle"
    /usr/bin/codesign --verify --strict "$bundle"
)
