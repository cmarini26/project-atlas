import { expect, test } from '@playwright/test'

test.describe('onboarding smoke', () => {
  test('creates a fresh client and lands on a recommendation page', async ({ page }) => {
    const runId = Date.now().toString()
    const email = `atlas-e2e-${runId}@example.test`
    const companyName = `Atlas E2E ${runId}`
    const password = 'SmokeTest123!'
    const website = 'https://cbbauctions.com'

    test.setTimeout(5 * 60 * 1000)

    await page.goto('/register')

    await page.getByLabel('Full name').fill('Atlas E2E Tester')
    await page.getByLabel('Email').fill(email)
    await page.locator('#password').fill(password)
    await page.locator('#password_confirmation').fill(password)
    await page.getByRole('button', { name: 'Create account' }).click()

    await page.waitForURL(/\/onboarding$/)
    await expect(page.getByRole('heading', { name: 'Tell us about your business' })).toBeVisible()

    await page.getByLabel('Business name').fill(companyName)
    await page.getByLabel(/Industry/).fill('Collectibles')
    await page.getByRole('button', { name: 'Continue' }).click()

    await expect(page.getByRole('heading', { name: 'Connect your website' })).toBeVisible()
    await page.getByLabel('Website URL').fill(website)
    await page.getByRole('button', { name: 'Connect website' }).click()

    await expect(page.getByRole('heading', { name: 'Where do your customers find you?' })).toBeVisible()
    await page.getByRole('button', { name: 'Finish setup' }).click()

    await page.waitForURL(/\/onboarding\/status/)
    await expect(page.getByText('Checking in with Atlas…')).toBeVisible()

    await page.waitForURL(/\/app\/recommendations\//, { timeout: 4 * 60 * 1000 })

    await expect(page).toHaveURL(/\/app\/recommendations\//)
    await expect(page.getByRole('heading', { name: /campaign/i })).toBeVisible()
    await expect(page.getByText('Pending review')).toBeVisible()
    await expect(page.getByText('Why Atlas recommends this')).toBeVisible()
  })
})
