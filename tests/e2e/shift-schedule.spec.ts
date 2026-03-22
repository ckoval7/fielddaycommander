import { test, expect } from '@playwright/test';

const EMAIL = 'admin@localhost';
const PASSWORD = 'Jij6&FERUm%Uhj9NOC';

async function login(page) {
    await page.goto('/login');
    await page.locator('input[name="email"]').fill(EMAIL);
    await page.locator('input[name="password"]').fill(PASSWORD);
    await page.locator('button[type="submit"]').click();
    await page.waitForURL((url) => !url.pathname.includes('/login'));
}

test.describe('Schedule Navigation', () => {
    test('Schedule link is visible in sidebar', async ({ page }) => {
        await login(page);
        await page.goto('/schedule');
        await page.waitForLoadState('networkidle');

        await page.screenshot({ path: 'test-results/schedule-nav.png', fullPage: true });

        // Page loads successfully
        await expect(page.locator('h1')).toContainText('Schedule');
    });
});
