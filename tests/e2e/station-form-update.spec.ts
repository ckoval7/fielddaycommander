import { test, expect } from '@playwright/test';
import { execFileSync } from 'child_process';

interface ScenarioData {
    user_email: string;
    user_password: string;
    station_id: number;
}

function seedScenario(): ScenarioData {
    const output = execFileSync('php', ['artisan', 'app:seed-playwright-data', 'station-update'], {
        cwd: process.cwd(),
        encoding: 'utf-8',
    });
    return JSON.parse(output.trim());
}

function cleanup() {
    execFileSync('php', ['artisan', 'app:seed-playwright-data', 'cleanup'], {
        cwd: process.cwd(),
        encoding: 'utf-8',
    });
}

async function login(page, email: string, password: string) {
    await page.goto('/login');
    await page.locator('input[name="email"]').fill(email);
    await page.locator('input[name="password"]').fill(password);
    await page.locator('button[type="submit"]').click();
    await page.waitForURL((url) => !url.pathname.includes('/login'));
}

test.describe('Station Form Update', () => {
    test.afterEach(() => {
        cleanup();
    });

    test('can update power source description', async ({ page }) => {
        const data = seedScenario();
        await login(page, data.user_email, data.user_password);

        await page.goto(`/stations/${data.station_id}/edit`);
        await page.waitForLoadState('networkidle');

        // Scroll down and find the textarea
        const textarea = page.locator('textarea').first();
        await textarea.scrollIntoViewIfNeeded();
        await expect(textarea).toBeVisible();

        // Fill new value and wait for Livewire debounce to sync
        await textarea.fill('Generator and Solar');
        await page.waitForTimeout(1000);

        // Wait for any Livewire network request from the live sync
        await page.waitForLoadState('networkidle');

        // Click Update and wait for Livewire response
        const [response] = await Promise.all([
            page.waitForResponse(resp => resp.url().includes('livewire') && resp.request().method() === 'POST'),
            page.getByRole('button', { name: /Update Station/i }).click(),
        ]);

        console.log('Livewire response status:', response.status());
        await page.waitForTimeout(2000);
        console.log('Final URL:', page.url());

        // Verify DB was updated
        const dbCheck = execFileSync('php', ['artisan', 'tinker', '--execute',
            `echo App\\Models\\Station::find(${data.station_id})->power_source_description;`
        ], { cwd: process.cwd(), encoding: 'utf-8' });

        const dbValue = dbCheck.trim();
        console.log('DB value after save:', dbValue);
        expect(dbValue).toBe('Generator and Solar');
    });
});
