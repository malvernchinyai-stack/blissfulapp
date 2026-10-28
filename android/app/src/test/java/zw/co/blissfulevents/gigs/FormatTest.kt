package zw.co.blissfulevents.gigs

import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test
import zw.co.blissfulevents.gigs.data.parseHttpError
import zw.co.blissfulevents.gigs.util.MoneyFormat
import zw.co.blissfulevents.gigs.util.TimeFormat
import java.time.LocalDate
import java.time.LocalTime
import java.time.ZoneId

class FormatTest {
    @Test fun moneyFormatsMinorUnitsExactly() {
        assertEquals("US$1,234.05", MoneyFormat.format(123405, "USD"))
        assertEquals("US$0.00", MoneyFormat.format(0, "USD"))
        assertEquals("-US$0.99", MoneyFormat.format(-99, "USD"))
        assertEquals("ZAR 10.00", MoneyFormat.format(1000, "ZAR"))
    }

    @Test fun moneyParsingNeverUsesFloats() {
        assertEquals(12050L, MoneyFormat.parse("120.5"))
        assertEquals(12005L, MoneyFormat.parse("120.05"))
        assertEquals(120000L, MoneyFormat.parse("1,200"))
        assertEquals(1L, MoneyFormat.parse("0.01"))
        assertNull(MoneyFormat.parse("1.005"))
        assertNull(MoneyFormat.parse("-5"))
        assertNull(MoneyFormat.parse("abc"))
        assertEquals("80.00", MoneyFormat.toInput(8000))
    }

    @Test fun feeMatchesServerRounding() {
        assertEquals(1000L, MoneyFormat.fee(10000, 1000))
        assertEquals(1L, MoneyFormat.fee(5, 1000)) // half-up
        assertEquals(0L, MoneyFormat.fee(4, 1000))
    }

    @Test fun eventTimesShowInEventZone() {
        // 07:30 UTC is 09:30 in Harare (UTC+2, no DST)
        val shown = TimeFormat.eventDateTime("2027-03-14T07:30:00Z", "Africa/Harare", ZoneId.of("Africa/Harare"))
        assertEquals("Sun 14 Mar 2027, 09:30", shown)
        val fromLondon = TimeFormat.eventDateTime("2027-03-14T07:30:00Z", "Africa/Harare", ZoneId.of("Europe/London"))
        assertTrue(fromLondon.endsWith("(Harare time)"))
    }

    @Test fun localInputBecomesOffsetIso() {
        assertEquals("2027-03-14T09:30:00+02:00", TimeFormat.toIso(LocalDate.of(2027, 3, 14), LocalTime.of(9, 30), "Africa/Harare"))
    }

    @Test fun serverErrorsAreParsedIntoFields() {
        val f = parseHttpError(422, """{"error":{"code":"validation_failed","message":"Some fields need attention.","fields":{"email":"Enter a valid email address."}}}""")
        assertEquals("validation_failed", f.code)
        assertEquals("Enter a valid email address.", f.fields["email"])
        val g = parseHttpError(503, "<html>gateway</html>")
        assertTrue(g.message.contains("Nothing was changed"))
    }
}
