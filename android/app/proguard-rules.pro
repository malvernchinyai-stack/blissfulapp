# kotlinx.serialization
-keepattributes *Annotation*, InnerClasses
-dontnote kotlinx.serialization.**
-keepclassmembers class kotlinx.serialization.json.** { *** Companion; }
-keepclasseswithmembers class kotlinx.serialization.json.** { kotlinx.serialization.KSerializer serializer(...); }
-keep,includedescriptorclasses class zw.co.blissfulevents.gigs.data.api.**$$serializer { *; }
-keepclassmembers class zw.co.blissfulevents.gigs.data.api.** { *** Companion; }
-keepclasseswithmembers class zw.co.blissfulevents.gigs.data.api.** { kotlinx.serialization.KSerializer serializer(...); }

# Retrofit (R8 full mode)
-keepattributes Signature, Exceptions
-keep,allowobfuscation,allowshrinking interface retrofit2.Call
-keep,allowobfuscation,allowshrinking class retrofit2.Response
-keep,allowobfuscation,allowshrinking class kotlin.coroutines.Continuation
-keep interface zw.co.blissfulevents.gigs.data.api.ApiService { *; }
-dontwarn okhttp3.internal.platform.**
-dontwarn org.conscrypt.**
-dontwarn org.bouncycastle.**
-dontwarn org.openjsse.**

# Never log in release
-assumenosideeffects class android.util.Log {
    public static int v(...);
    public static int d(...);
    public static int i(...);
}
