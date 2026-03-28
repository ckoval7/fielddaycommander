import { test, expect, Page } from '@playwright/test';

const EMAIL = 'admin@localhost';
const PASSWORD = 'Jij6&FERUm%Uhj9NOC';

async function login(page: Page) {
    await page.goto('/login');
    await page.locator('input[name="email"]').fill(EMAIL);
    await page.locator('input[name="password"]').fill(PASSWORD);
    await page.locator('button[type="submit"]').click();
    await page.waitForURL((url) => !url.pathname.includes('/login'));
}

test.describe('Site Safety Checklist', () => {

    test('page loads and displays checklist items correctly', async ({ page }) => {
        await login(page);
        await page.goto('/site-safety');
        await page.waitForLoadState('networkidle');

        // Page loads with correct heading
        await expect(page.locator('h1')).toContainText('Site Safety Checklist');

        // Should show checklist items with required badges
        const requiredBadges = page.locator('text=Required');
        await expect(requiredBadges.first()).toBeVisible({ timeout: 10000 });

        // Should show completion summary badges
        await expect(page.locator(String.raw`text=/\d+ of \d+ complete/`)).toBeVisible();
        await expect(page.locator(String.raw`text=/\d+ of \d+ required/`)).toBeVisible();

        // Should show bonus point badges
        await expect(page.locator('text=/bonus pts/')).toBeVisible();

        // Should show checklist type section header
        const typeHeader = page.locator('text=/Safety Officer|Site Responsibilities/');
        await expect(typeHeader.first()).toBeVisible();

        // Should have checkboxes
        const checkboxes = page.locator('.checkbox');
        await expect(checkboxes.first()).toBeVisible();
        const count = await checkboxes.count();
        expect(count).toBeGreaterThan(0);
    });

    test('checkboxes reflect edit permissions correctly', async ({ page }) => {
        await login(page);
        await page.goto('/site-safety');
        await page.waitForLoadState('networkidle');

        const checkboxes = page.locator('.checkbox');
        await expect(checkboxes.first()).toBeVisible({ timeout: 10000 });

        // Check if user can edit (checkboxes enabled) or not (disabled)
        const firstCheckbox = checkboxes.first();
        const isDisabled = await firstCheckbox.isDisabled();

        if (isDisabled) {
            // All checkboxes should be disabled (read-only mode)
            const allCheckboxes = await checkboxes.all();
            for (const cb of allCheckboxes) {
                await expect(cb).toBeDisabled();
            }
        } else {
            // User has edit access — verify notes inputs are also present
            const notesInputs = page.locator('input[placeholder="Add notes..."]');
            await expect(notesInputs.first()).toBeVisible();
        }
    });
});

test.describe('Manage Safety Checklist', () => {

    test('manage page loads and shows default items', async ({ page }) => {
        await login(page);
        await page.goto('/site-safety/manage');
        await page.waitForLoadState('networkidle');

        // Page loads with correct heading
        await expect(page.locator('h1')).toContainText('Manage Safety Checklist');

        // Should have Default badges for ARRL items
        await expect(page.locator('text=Default').first()).toBeVisible({ timeout: 10000 });

        // Should have checklist type badges
        const typeBadge = page.locator('text=/Safety Officer|Site Responsibilities/');
        await expect(typeBadge.first()).toBeVisible();

        // Delete button should be disabled for default items
        const defaultCard = page.locator('.card', { has: page.locator('text=Default') }).first();
        const deleteBtn = defaultCard.locator(String.raw`button[wire\:click*="deleteItem"]`);
        await expect(deleteBtn).toBeDisabled();
    });

    test('can add and delete a custom checklist item', async ({ page }) => {
        await login(page);
        await page.goto('/site-safety/manage');
        await page.waitForLoadState('networkidle');

        // Click "Add Item" button
        await page.getByRole('button', { name: 'Add Item' }).click();

        // Fill in the modal form
        const labelInput = page.locator(String.raw`input[wire\:model="itemLabel"]`);
        await expect(labelInput).toBeVisible({ timeout: 5000 });
        await labelInput.fill('E2E Test Custom Safety Item');

        // Select checklist type from the select dropdown
        const typeSelect = page.locator(String.raw`select[wire\:model="itemChecklistType"]`);
        if (await typeSelect.isVisible()) {
            await typeSelect.selectOption('safety_officer');
        }

        // Save
        await page.getByRole('button', { name: 'Save' }).click();
        await page.waitForLoadState('networkidle');

        // Verify the item appears in the list
        await expect(page.locator('text=E2E Test Custom Safety Item')).toBeVisible({ timeout: 10000 });

        // Now delete the custom item to clean up
        const card = page.locator('.card', { has: page.locator('text=E2E Test Custom Safety Item') });
        page.on('dialog', dialog => dialog.accept());
        const deleteBtn = card.locator(String.raw`button[wire\:click*="deleteItem"]`);
        await deleteBtn.click();
        await page.waitForLoadState('networkidle');

        // Verify it's gone
        await expect(page.locator('text=E2E Test Custom Safety Item')).not.toBeVisible({ timeout: 10000 });
    });

    test('seed defaults, back navigation, and sidebar links work', async ({ page }) => {
        await login(page);

        // Check sidebar has Site Safety and Manage links
        await page.goto('/');
        await page.waitForLoadState('networkidle');
        await expect(page.locator('a:has-text("Site Safety")')).toBeVisible({ timeout: 10000 });
        await expect(page.locator('a:has-text("Manage Safety Checklist")')).toBeVisible();

        // Navigate to manage page
        await page.goto('/site-safety/manage');
        await page.waitForLoadState('networkidle');

        // Click "Seed ARRL Defaults" — should not duplicate if already seeded
        await page.getByRole('button', { name: 'Seed ARRL Defaults' }).click();
        await page.waitForLoadState('networkidle');

        // Should still show items (no errors)
        await expect(page.locator('text=Default').first()).toBeVisible({ timeout: 10000 });

        // Click back arrow to navigate to checklist view
        const backLink = page.locator('a[href*="/site-safety"]').first();
        await backLink.click();
        await page.waitForLoadState('networkidle');

        await expect(page.locator('h1')).toContainText('Site Safety Checklist');
    });
});
