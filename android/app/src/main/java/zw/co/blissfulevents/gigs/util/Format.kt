package zw.co.blissfulevents.gigs.util

import java.time.Instant
import java.time.LocalDate
import java.time.LocalTime
import java.time.ZoneId
import java.time.ZonedDateTime
import java.time.format.DateTimeFormatter
import java.time.temporal.ChronoUnit
import java.util.Locale

/** Money is always integer minor units. Formatting uses integer arithmetic only. */
object MoneyFormat {
    fun format(minor: Long, currency: String): String {
        val sign = if (minor < 0) "-" else ""
        val abs = kotlin.math.abs(minor)
        val whole = String.format(Locale.US, "%,d", abs / 100)
        val cents = (abs % 100).toString().padStart(2, '0')
        val symbol = if (currency == "USD") "US$" else "$currency "
        return "$sign$symbol$whole.$cents"
    }

    /** Parses user input like "120", "120.5", "1,200.50" into cents. Returns null if invalid. */
    fun parse(input: String): Long? {
        val s = input.trim().replace(",", "").removePrefix("$").removePrefix("US$")
        if (!Regex("""^\d{1,9}(\.\d{1,2})?$""").matches(s)) return null
        val parts = s.split('.')
        val whole = parts[0].toLong()
        val frac = if (parts.size > 1) parts[1].padEnd(2, '0').toLong() else 0L
        return whole * 100 + frac
    }

    fun toInput(minor: Long): String = "${minor / 100}.${(minor % 100).toString().padStart(2, '0')}"

    /** Same rounding as the server: half-up on basis points. */
    fun fee(subtotalMinor: Long, bps: Int): Long = (subtotalMinor * bps + 5000) / 10000
}

/** Server timestamps are UTC; events are displayed in the event's own time zone (and labelled if it differs from the phone's). */
object TimeFormat {
    private val day = DateTimeFormatter.ofPattern("EEE d MMM yyyy", Locale.ENGLISH)
    private val time = DateTimeFormatter.ofPattern("HH:mm", Locale.ENGLISH)

    fun parse(iso: String): Instant = Instant.parse(iso)

    fun eventDateTime(iso: String, zone: String, deviceZone: ZoneId = ZoneId.systemDefault()): String {
        val z = runCatching { ZoneId.of(zone) }.getOrDefault(ZoneId.of("Africa/Harare"))
        val dt = parse(iso).atZone(z)
        val base = "${day.format(dt)}, ${time.format(dt)}"
        return if (z.rules.getOffset(dt.toInstant()) != deviceZone.rules.getOffset(dt.toInstant())) "$base (${z.id.substringAfter('/').replace('_', ' ')} time)" else base
    }

    fun eventTimeRange(startIso: String, endIso: String, zone: String): String {
        val z = runCatching { ZoneId.of(zone) }.getOrDefault(ZoneId.of("Africa/Harare"))
        val s = parse(startIso).atZone(z)
        val e = parse(endIso).atZone(z)
        return "${day.format(s)}, ${time.format(s)}–${time.format(e)}"
    }

    fun relative(iso: String, now: Instant = Instant.now()): String {
        val then = parse(iso)
        val mins = ChronoUnit.MINUTES.between(then, now)
        return when {
            mins < 1 -> "just now"
            mins < 60 -> "$mins min ago"
            mins < 60 * 24 -> "${mins / 60} h ago"
            mins < 60 * 24 * 7 -> "${mins / (60 * 24)} d ago"
            else -> day.format(then.atZone(ZoneId.systemDefault()))
        }
    }

    fun until(iso: String, now: Instant = Instant.now()): String {
        val mins = ChronoUnit.MINUTES.between(now, parse(iso))
        return when {
            mins <= 0 -> "closed"
            mins < 60 -> "$mins min left"
            mins < 60 * 48 -> "${mins / 60} h left"
            else -> "${mins / (60 * 24)} days left"
        }
    }

    /** Combines a local date + time in the event zone into an ISO instant with offset for the API. */
    fun toIso(date: LocalDate, t: LocalTime, zone: String): String =
        ZonedDateTime.of(date, t, ZoneId.of(zone)).format(DateTimeFormatter.ISO_OFFSET_DATE_TIME)

    fun duration(minutes: Int): String = when {
        minutes % 60 == 0 -> "${minutes / 60} h"
        minutes > 60 -> "${minutes / 60} h ${minutes % 60} min"
        else -> "$minutes min"
    }
}

object Labels {
    fun human(slug: String): String = slug.replace('_', ' ').replaceFirstChar { it.titlecase(Locale.ENGLISH) }

    fun bookingStatus(s: String): String = when (s) {
        "confirmed" -> "Confirmed"
        "in_progress" -> "In progress"
        "completed" -> "Completed"
        "cancelled" -> "Cancelled"
        "disputed" -> "Under review"
        else -> human(s)
    }

    fun paymentStatus(s: String): String = when (s) {
        "unpaid" -> "Not paid yet"
        "pending" -> "Payment pending"
        "paid" -> "Paid"
        "failed" -> "Payment failed"
        "cancelled" -> "Payment cancelled"
        "refunded" -> "Refunded"
        "partially_refunded" -> "Partly refunded"
        else -> human(s)
    }
}
