import { expect, test } from '@playwright/test'

test.describe('onboarding smoke', () => {
  test('walks the new business discovery wizard through to a real recommendation', async ({ page }) => {
    test.setTimeout(5 * 60 * 1000)

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

    // Step 5: Asset Details (current flow is website-only)
    await expect(page.getByRole('heading', { name: 'Tell us a bit more about your website' })).toBeVisible()
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

    // Business Discovery now really runs: a real crawl of the declared
    // website, real Fact extraction, real Opportunity/Decision/Campaign
    // generation, ending in a real Recommendation — Status.vue polls and
    // redirects there automatically once one exists.
    await page.waitForURL(/\/onboarding\/status/)
    await page.waitForURL(/\/app\/recommendations\//, { timeout: 4 * 60 * 1000 })

    await expect(page).toHaveURL(/\/app\/recommendations\//)
    await expect(page.getByRole('heading', { name: /campaign/i })).toBeVisible()
    await expect(page.getByText('Pending review')).toBeVisible()
  })
})
