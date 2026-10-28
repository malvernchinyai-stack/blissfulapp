package zw.co.blissfulevents.gigs

import kotlinx.coroutines.test.runTest
import okhttp3.mockwebserver.Dispatcher
import okhttp3.mockwebserver.MockResponse
import okhttp3.mockwebserver.MockWebServer
import okhttp3.mockwebserver.RecordedRequest
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test
import zw.co.blissfulevents.gigs.data.ApiResult
import zw.co.blissfulevents.gigs.data.apiCall
import zw.co.blissfulevents.gigs.data.api.AcceptBody
import zw.co.blissfulevents.gigs.data.api.ApiClient
import zw.co.blissfulevents.gigs.data.api.MessageBody
import zw.co.blissfulevents.gigs.data.auth.MemoryTokenStore
import java.util.concurrent.CopyOnWriteArrayList
import java.util.concurrent.TimeUnit

class ApiClientTest {
    private lateinit var server: MockWebServer
    private val seen = CopyOnWriteArrayList<RecordedRequest>()
    private val me = """{"id":7,"email":"a@b.test","display_name":"A","roles":["client"],"active_role":"client"}"""

    @Before fun setUp() { server = MockWebServer(); server.start() }
    @After fun tearDown() { server.shutdown() }

    private fun client(tokens: MemoryTokenStore) = ApiClient(server.url("/api/").toString(), tokens, "test", timeoutSeconds = 5)

    @Test fun expiredAccessTokenIsRefreshedOnceAndRetried() = runTest {
        val tokens = MemoryTokenStore().apply { save("old-access", "refresh-1") }
        server.dispatcher = object : Dispatcher() {
            override fun dispatch(request: RecordedRequest): MockResponse {
                seen += request
                return when (request.path) {
                    "/api/auth/refresh" -> MockResponse().setBody("""{"access_token":"new-access","refresh_token":"refresh-2","expires_in":3600,"user":$me}""")
                    "/api/me" -> if (request.getHeader("Authorization") == "Bearer new-access") MockResponse().setBody(me)
                                 else MockResponse().setResponseCode(401).setBody("""{"error":{"code":"unauthorized","message":"Please sign in again."}}""")
                    else -> MockResponse().setResponseCode(404)
                }
            }
        }
        val r = apiCall { client(tokens).api.me() }
        assertTrue(r is ApiResult.Ok)
        assertEquals("new-access", tokens.accessToken)
        assertEquals("refresh-2", tokens.refreshToken)
        assertEquals(1, seen.count { it.path == "/api/auth/refresh" })
    }

    @Test fun rejectedRefreshSignsOutAndEmits() = runTest {
        val tokens = MemoryTokenStore().apply { save("old", "revoked") }
        server.dispatcher = object : Dispatcher() {
            override fun dispatch(request: RecordedRequest) = MockResponse().setResponseCode(401)
                .setBody("""{"error":{"code":"unauthorized","message":"Please sign in again."}}""")
        }
        val c = client(tokens)
        val r = apiCall { c.api.me() }
        assertTrue(r is ApiResult.Err && (r as ApiResult.Err).failure.httpStatus == 401)
        assertNull(tokens.refreshToken)
    }

    @Test fun networkFailureDuringRefreshKeepsTheSession() = runTest {
        val tokens = MemoryTokenStore().apply { save("old", "refresh-1") }
        server.dispatcher = object : Dispatcher() {
            override fun dispatch(request: RecordedRequest): MockResponse = if (request.path == "/api/auth/refresh")
                MockResponse().setResponseCode(502) else MockResponse().setResponseCode(401)
        }
        val r = apiCall { client(tokens).api.me() }
        assertTrue(r is ApiResult.Err && (r as ApiResult.Err).failure.offline)
        assertEquals("refresh-1", tokens.refreshToken)
    }

    @Test fun writesCarryIdempotencyKeysAndExplicitKeysAreKept() = runTest {
        val tokens = MemoryTokenStore().apply { save("tok", "ref") }
        server.dispatcher = object : Dispatcher() {
            override fun dispatch(request: RecordedRequest): MockResponse {
                seen += request
                return MockResponse().setBody("""{"id":1,"mine":true,"body":"hi","created_at":"2027-01-01T00:00:00Z"}""")
            }
        }
        val c = client(tokens)
        apiCall { c.api.sendMessage(5, MessageBody("hi"), "fixed-key-123") }
        apiCall { c.api.logout() }
        assertEquals("fixed-key-123", seen[0].getHeader("Idempotency-Key"))
        assertNotNull(seen[1].getHeader("Idempotency-Key"))
        assertEquals("Bearer tok", seen[0].getHeader("Authorization"))
        assertTrue(seen[0].body.readUtf8().contains("\"body\":\"hi\""))
    }

    @Test fun conflictMessageFromServerIsShownVerbatim() = runTest {
        val tokens = MemoryTokenStore().apply { save("tok", "ref") }
        server.enqueue(MockResponse().setResponseCode(409)
            .setBody("""{"error":{"code":"offer_changed","message":"The offer changed after you opened it. Please review the new terms."}}"""))
        val r = apiCall { client(tokens).api.acceptOffer(1, AcceptBody(1), "k-12345678") }
        val f = (r as ApiResult.Err).failure
        assertEquals("offer_changed", f.code)
        assertTrue(f.message.startsWith("The offer changed"))
    }

    @Test fun timeoutIsReportedAsOffline() = runTest {
        val tokens = MemoryTokenStore().apply { save("tok", "ref") }
        server.enqueue(MockResponse().setBody(me).setHeadersDelay(10, TimeUnit.SECONDS))
        val r = apiCall { client(tokens).api.me() }
        assertTrue((r as ApiResult.Err).failure.offline)
    }

    @Test fun snakeCaseResponsesDecode() = runTest {
        val tokens = MemoryTokenStore().apply { save("tok", "ref") }
        server.enqueue(MockResponse().setBody(me))
        val r = apiCall { client(tokens).api.me() }
        assertEquals("A", (r as ApiResult.Ok).value.displayName)
    }
}
