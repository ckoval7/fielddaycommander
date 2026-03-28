import { test, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';

interface ScenarioData {
    user_email: string;
    user_password: string;
    station_id: number;
    equipment_id: number;
    equipment_label: string;
}

function seedScenario(scenario: string): ScenarioData {
    const output = execFileSync('php', ['artisan', 'app:seed-playwright-data', scenario], {
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

async function navigateToEquipmentTab(page, stationId: number) {
    await page.goto(`/stations/${stationId}/edit`);
    await page.waitForLoadState('networkidle');

    // Click the Equipment tab
    const equipmentTab = page.getByRole('tab', { name: /Equipment/i });
    await equipmentTab.click();

    // Wait for the equipment assignment component to load
    await page.waitForSelector('text=Equipment Assignment', { timeout: 10000 });
}

test.describe('Equipment Assignment Warning Dialogs', () => {
    test.afterEach(() => {
        cleanup();
    });

    test('shows warning modal when assigning antenna with incompatible bands', async ({ page }) => {
        const data = seedScenario('equipment-warning-band');
        await login(page, data.user_email, data.user_password);
        await navigateToEquipmentTab(page, data.station_id);

        // Find the equipment in the committed tab and click Assign
        const equipmentCard = page.locator('.space-y-2').filter({ hasText: data.equipment_label }).first();
        await equipmentCard.getByRole('button', { name: /Assign/i }).first().click();

        // Wait for and verify the warning modal appears
        const modal = page.getByText('Assignment Warnings');
        await expect(modal).toBeVisible({ timeout: 5000 });

        // Verify band compatibility warning is shown
        await expect(page.getByText('Band Compatibility')).toBeVisible();
        await expect(page.getByText(/may not be compatible/i)).toBeVisible();

        // Verify both action buttons are present
        await expect(page.getByRole('button', { name: 'Cancel' })).toBeVisible();
        await expect(page.getByRole('button', { name: 'Assign Anyway' })).toBeVisible();
    });

    test('shows warning modal when assigning amplifier exceeding power limits', async ({ page }) => {
        const data = seedScenario('equipment-warning-power');
        await login(page, data.user_email, data.user_password);
        await navigateToEquipmentTab(page, data.station_id);

        // Find and click Assign for the amplifier
        const equipmentCard = page.locator('.space-y-2').filter({ hasText: data.equipment_label }).first();
        await equipmentCard.getByRole('button', { name: /Assign/i }).first().click();

        // Verify the warning modal appears with power warning
        await expect(page.getByText('Assignment Warnings')).toBeVisible({ timeout: 5000 });
        await expect(page.getByText('Power Limit')).toBeVisible();
        await expect(page.getByText(/exceeds/i)).toBeVisible();
    });

    test('cancelling warning modal does not assign equipment', async ({ page }) => {
        const data = seedScenario('equipment-warning-band');
        await login(page, data.user_email, data.user_password);
        await navigateToEquipmentTab(page, data.station_id);

        // Find and click Assign
        const equipmentCard = page.locator('.space-y-2').filter({ hasText: data.equipment_label }).first();
        await equipmentCard.getByRole('button', { name: /Assign/i }).first().click();

        // Wait for warning modal
        await expect(page.getByText('Assignment Warnings')).toBeVisible({ timeout: 5000 });

        // Click Cancel
        await page.getByRole('button', { name: 'Cancel' }).click();

        // Modal should close
        await expect(page.getByText('Assignment Warnings')).not.toBeVisible({ timeout: 5000 });

        // Equipment should still be in the available list (not assigned)
        await expect(page.locator('.space-y-2').filter({ hasText: data.equipment_label }).first()).toBeVisible();

        // Verify it's NOT in the assigned section
        const assignedSection = page.locator('section').filter({ hasText: 'Assigned to Station' });
        await expect(assignedSection.getByText(data.equipment_label)).not.toBeVisible();
    });

    test('confirming warning modal assigns the equipment', async ({ page }) => {
        const data = seedScenario('equipment-warning-band');
        await login(page, data.user_email, data.user_password);
        await navigateToEquipmentTab(page, data.station_id);

        // Find and click Assign
        const equipmentCard = page.locator('.space-y-2').filter({ hasText: data.equipment_label }).first();
        await equipmentCard.getByRole('button', { name: /Assign/i }).first().click();

        // Wait for warning modal
        await expect(page.getByText('Assignment Warnings')).toBeVisible({ timeout: 5000 });

        // Click "Assign Anyway"
        await page.getByRole('button', { name: 'Assign Anyway' }).click();

        // Modal should close
        await expect(page.getByText('Assignment Warnings')).not.toBeVisible({ timeout: 5000 });

        // Wait for Livewire to process and re-render
        await page.waitForLoadState('networkidle');

        // Equipment should now appear in the assigned section
        const assignedSection = page.locator('section').filter({ hasText: 'Assigned to Station' });
        await expect(assignedSection.getByText(data.equipment_label)).toBeVisible({ timeout: 5000 });
    });

    test('compatible equipment assigns without showing warning modal', async ({ page }) => {
        const data = seedScenario('equipment-no-warning');
        await login(page, data.user_email, data.user_password);
        await navigateToEquipmentTab(page, data.station_id);

        // Find and click Assign for compatible equipment
        const equipmentCard = page.locator('.space-y-2').filter({ hasText: data.equipment_label }).first();
        await equipmentCard.getByRole('button', { name: /Assign/i }).first().click();

        // Warning modal should NOT appear
        await expect(page.getByText('Assignment Warnings')).not.toBeVisible({ timeout: 2000 });

        // Wait for Livewire to process
        await page.waitForLoadState('networkidle');

        // Equipment should be assigned directly
        const assignedSection = page.locator('section').filter({ hasText: 'Assigned to Station' });
        await expect(assignedSection.getByText(data.equipment_label)).toBeVisible({ timeout: 5000 });
    });
});
