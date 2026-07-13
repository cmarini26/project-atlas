import { expect, test } from '@playwright/test'

test.describe('onboarding smoke', () => {
  test('walks the new business discovery wizard and reaches the Discovery placeholder', async ({ page }) => {
    const runId = Date.now().toString()
    const email = `atlas-e2e-${runId}@example.test`
    const companyName = `Atlas E2E ${runId}`
    const password = 'SmokeTest123!'

    await page.goto('/register')

    await page.getByLabel('Full name').fill('Atlas E2E Tester')
    await page.getByLabel('Email').fill(email)
    await page.locator('#password').fill(password)
    await page.locator('#password_confirmation').fill(password)
    await page.getByRole('button', { name: 'Create account' }).click()

    await page.waitForURL(/\/onboarding$/)

    // Step 1: Welcome
    await expect(page.getByRole('heading', { name: 'Welcome to Atlas' })).toBeVisible()
    await page.getByRole('button', { name: "Let's Begin" }).click()

    // Step 2: Company
    await expect(page.getByRole('heading', { name: 'Tell us about your business' })).toBeVisible()
    await page.locator('#company-name').fill(companyName)
    await page.locator('#industry').fill('Collectibles')
    await page.getByRole('button', { name: 'Continue' }).click()

    // Step 3: Business Goals
    await expect(page.getByRole('heading', { name: 'What would you like Atlas to help you accomplish?' })).toBeVisible()
    await page.getByText('Increase sales').click()
    await page.getByRole('button', { name: 'Continue' }).click()

    // Step 4: Marketing Assets
    await expect(page.getByRole('heading', { name: 'Where can customers find your business?' })).toBeVisible()
    await page.getByText('Website', { exact: true }).click()
    await page.getByRole('button', { name: 'Continue' }).click()

    // Step 5: Asset Details
    await expect(page.getByRole('heading', { name: 'Tell us a bit more about each one' })).toBeVisible()
    await page.locator('#detail-website-url').fill('https://cbbauctions.com')
    await page.locator('#detail-website-platform').selectOption('custom')
    await page.getByRole('button', { name: 'Continue' }).click()

    // Step 6: Marketing Preferences
    await expect(page.getByRole('heading', { name: 'A few quick preferences' })).toBeVisible()
    await page.locator('#marketing-frequency').selectOption('weekly')
    await page.locator('#marketing-owner').selectOption('me')
    await page.getByRole('button', { name: 'No' }).click()
    await page.locator('#primary-cta').selectOption('buy_online')
    await page.getByRole('button', { name: 'Continue' }).click()

    // Step 7: Discovery Placeholder
    await expect(page.getByRole('heading', { name: 'Atlas is ready to learn about your business.' })).toBeVisible()
    await page.getByRole('button', { name: 'Start Discovery' }).click()

    // Phase 1 only persists onboarding data and navigates to the existing
    // placeholder/status screen — it does not dispatch real discovery, so
    // this test stops here rather than waiting for a recommendation that
    // Phase 1 never produces.
    await page.waitForURL(/\/onboarding\/status/)
  })
})
