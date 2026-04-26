# Keep Flutter
-keep class io.flutter.** { *; }
-keep class io.flutter.plugins.** { *; }

# Firebase + FCM
-keep class com.google.firebase.** { *; }
-keep class com.google.android.gms.** { *; }

# just_audio_background
-keep class com.ryanheise.audioservice.** { *; }
-keep class com.ryanheise.just_audio.** { *; }

# JSON model classes (annotation-based)
-keepclasseswithmembers class * { @com.google.gson.annotations.SerializedName <fields>; }
