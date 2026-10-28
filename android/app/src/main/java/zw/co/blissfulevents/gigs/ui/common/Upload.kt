package zw.co.blissfulevents.gigs.ui.common

import android.content.Context
import android.net.Uri
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.MultipartBody
import okhttp3.RequestBody.Companion.toRequestBody
import zw.co.blissfulevents.gigs.data.api.ApiService
import zw.co.blissfulevents.gigs.data.api.UploadResult
import java.io.IOException

/** Reads a picked file (max 10 MB, checked before upload) and sends it. The server re-validates type and size. */
suspend fun uploadUri(context: Context, api: ApiService, uri: Uri, purpose: String): UploadResult {
    val (bytes, mime) = withContext(Dispatchers.IO) {
        val cr = context.contentResolver
        val type = cr.getType(uri) ?: "application/octet-stream"
        val data = cr.openInputStream(uri)?.use { input ->
            val buf = java.io.ByteArrayOutputStream()
            val chunk = ByteArray(64 * 1024)
            var total = 0
            while (true) {
                val n = input.read(chunk)
                if (n < 0) break
                total += n
                if (total > 10_000_000) throw IOException("File is larger than 10 MB")
                buf.write(chunk, 0, n)
            }
            buf.toByteArray()
        } ?: throw IOException("Could not read the selected file")
        data to type
    }
    val part = MultipartBody.Part.createFormData("file", "upload", bytes.toRequestBody(mime.toMediaType()))
    return api.upload(purpose.toRequestBody("text/plain".toMediaType()), part)
}
