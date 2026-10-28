package zw.co.blissfulevents.gigs

import androidx.compose.ui.test.assertIsDisplayed
import androidx.compose.ui.test.junit4.createAndroidComposeRule
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performClick
import androidx.test.ext.junit.runners.AndroidJUnit4
import org.junit.Rule
import org.junit.Test
import org.junit.runner.RunWith

/** Emulator smoke test (runs in CI): fresh install shows onboarding and reaches sign-up and sign-in. */
@RunWith(AndroidJUnit4::class)
class WelcomeFlowTest {
    @get:Rule val rule = createAndroidComposeRule<MainActivity>()

    @Test fun onboardingLeadsToSignUpAndSignIn() {
        rule.onNodeWithText("Live music for every occasion").assertIsDisplayed()
        rule.onNodeWithText("Next").performClick()
        rule.onNodeWithText("Next").performClick()
        rule.onNodeWithText("Create an account").performClick()
        rule.onNodeWithText("I want to book musicians").assertIsDisplayed()
        rule.activityRule.scenario.onActivity { it.onBackPressedDispatcher.onBackPressed() }
        rule.onNodeWithText("I already have an account").performClick()
        rule.onNodeWithText("Forgot password?").assertIsDisplayed()
    }
}
