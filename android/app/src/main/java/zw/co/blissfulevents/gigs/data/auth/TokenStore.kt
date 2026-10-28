package zw.co.blissfulevents.gigs.data.auth

import android.content.Context
import android.content.SharedPreferences
import android.security.keystore.KeyGenParameterSpec
import android.security.keystore.KeyProperties
import android.util.Base64
import java.security.KeyStore
import javax.crypto.Cipher
import javax.crypto.KeyGenerator
import javax.crypto.SecretKey
import javax.crypto.spec.GCMParameterSpec

/** Session tokens persisted with AES-256-GCM. The key never leaves Android Keystore (MASVS-STORAGE-1, MASVS-CRYPTO-2). */
interface TokenStore {
    val accessToken: String?
    val refreshToken: String?
    fun save(access: String, refresh: String)
    fun clear()
}

class KeystoreTokenStore(context: Context) : TokenStore {
    private val prefs: SharedPreferences = context.getSharedPreferences("secure_session", Context.MODE_PRIVATE)
    private val lock = Any()
    @Volatile private var cachedAccess: String? = null
    @Volatile private var cachedRefresh: String? = null
    @Volatile private var loaded = false

    override val accessToken: String? get() { load(); return cachedAccess }
    override val refreshToken: String? get() { load(); return cachedRefresh }

    override fun save(access: String, refresh: String) = synchronized(lock) {
        prefs.edit().putString(KEY_ACCESS, encrypt(access)).putString(KEY_REFRESH, encrypt(refresh)).commit()
        cachedAccess = access
        cachedRefresh = refresh
        loaded = true
    }

    override fun clear() = synchronized(lock) {
        prefs.edit().clear().commit()
        cachedAccess = null
        cachedRefresh = null
        loaded = true
    }

    private fun load() {
        if (loaded) return
        synchronized(lock) {
            if (loaded) return
            cachedAccess = prefs.getString(KEY_ACCESS, null)?.let { decrypt(it) }
            cachedRefresh = prefs.getString(KEY_REFRESH, null)?.let { decrypt(it) }
            if (cachedRefresh == null) prefs.edit().clear().apply()
            loaded = true
        }
    }

    private fun key(): SecretKey {
        val ks = KeyStore.getInstance("AndroidKeyStore").apply { load(null) }
        (ks.getEntry(ALIAS, null) as? KeyStore.SecretKeyEntry)?.let { return it.secretKey }
        val gen = KeyGenerator.getInstance(KeyProperties.KEY_ALGORITHM_AES, "AndroidKeyStore")
        gen.init(
            KeyGenParameterSpec.Builder(ALIAS, KeyProperties.PURPOSE_ENCRYPT or KeyProperties.PURPOSE_DECRYPT)
                .setBlockModes(KeyProperties.BLOCK_MODE_GCM)
                .setEncryptionPaddings(KeyProperties.ENCRYPTION_PADDING_NONE)
                .setKeySize(256)
                .build()
        )
        return gen.generateKey()
    }

    private fun encrypt(plain: String): String {
        val cipher = Cipher.getInstance(TRANSFORM)
        cipher.init(Cipher.ENCRYPT_MODE, key())
        val out = cipher.iv + cipher.doFinal(plain.toByteArray(Charsets.UTF_8))
        return Base64.encodeToString(out, Base64.NO_WRAP)
    }

    /** Returns null (forcing sign-in) if the key was lost, e.g. after a device restore. */
    private fun decrypt(encoded: String): String? = try {
        val raw = Base64.decode(encoded, Base64.NO_WRAP)
        val cipher = Cipher.getInstance(TRANSFORM)
        cipher.init(Cipher.DECRYPT_MODE, key(), GCMParameterSpec(128, raw, 0, 12))
        String(cipher.doFinal(raw, 12, raw.size - 12), Charsets.UTF_8)
    } catch (e: Exception) {
        null
    }

    private companion object {
        const val ALIAS = "blissful_session_key"
        const val TRANSFORM = "AES/GCM/NoPadding"
        const val KEY_ACCESS = "a"
        const val KEY_REFRESH = "r"
    }
}

/** In-memory store for unit tests. */
class MemoryTokenStore : TokenStore {
    override var accessToken: String? = null
        private set
    override var refreshToken: String? = null
        private set
    override fun save(access: String, refresh: String) { accessToken = access; refreshToken = refresh }
    override fun clear() { accessToken = null; refreshToken = null }
}
