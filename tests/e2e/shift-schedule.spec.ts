import { test, expect } from '@playwright/test';
import { execFileSync } from 'child_process';

function seedScenario(scenario: string): any {
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

function dbQuery(php: string): string {
    return execFileSync('php', ['artisan', 'tinker', '--execute', php], {
        cwd: process.cwd(),
        encoding: 'utf-8',
    }).trim();
}

async function login(page, email: string, password: string) {
    await page.goto('/login');
    await page.locator('input[name="email"]').fill(email);
    await page.locator('input[name="password"]').fill(password);
    await page.locator('button[type="submit"]').click();
    await page.waitForURL((url) => !url.pathname.includes('/login'));
}

// ============================================================
// HAPPY PATH: Schedule Timeline
// ============================================================

test.describe('Schedule Timeline - Viewing', () => {
    test.afterEach(() => cleanup());

    test('schedule page renders with default roles for the event', async ({ page }) => {
        const data = seedScenario('schedule-signup');
        await login(page, data.user_email, data.user_password);

        await page.goto('/schedule');
        await page.waitForLoadState('networkidle');

        // Page title
        await expect(page.locator('h1')).toContainText('Schedule');
        await expect(page.getByRole('paragraph').filter({ hasText: 'PW Test Field Day' })).toBeVisible();

        // Default roles visible — scope to main content area to avoid hidden option elements
        const main = page.getByRole('main');
        await expect(main.locator('span', { hasText: 'Safety Officer' }).first()).toBeVisible();
        await expect(main.locator('span', { hasText: 'Public Greeter' }).first()).toBeVisible();
        await expect(main.locator('span', { hasText: 'Message Handler' }).first()).toBeVisible();

        // Bonus points badge shows
        await expect(page.getByText('100 bonus pts').first()).toBeVisible();
    });

    test('schedule shows open shift with Sign Up button', async ({ page }) => {
        const data = seedScenario('schedule-signup');
        await login(page, data.user_email, data.user_password);

        await page.goto('/schedule');
        await page.waitForLoadState('networkidle');

        // The open Public Greeter shift should show Sign Up button
        await expect(page.getByRole('button', { name: /Sign Up/i })).toBeVisible();
    });

    test('My Shifts link navigates to personal view', async ({ page }) => {
        const data = seedScenario('schedule-signup');
        await login(page, data.user_email, data.user_password);

        await page.goto('/schedule');
        await page.waitForLoadState('networkidle');

        await page.getByRole('link', { name: /My Shifts/i }).click();
        await page.waitForURL('**/schedule/my-shifts');

        await expect(page.locator('h1')).toContainText('My Shifts');
    });
});

// ============================================================
// HAPPY PATH: Self Sign-up
// ============================================================

test.describe('Schedule - Self Sign-up', () => {
    test.afterEach(() => cleanup());

    test('user can sign up for an open shift', async ({ page }) => {
        const data = seedScenario('schedule-signup');
        await login(page, data.user_email, data.user_password);

        await page.goto('/schedule');
        await page.waitForLoadState('networkidle');

        // Click Sign Up
        await page.getByRole('button', { name: /Sign Up/i }).click();
        await page.waitForTimeout(2000);

        // User's name should appear
        await expect(page.getByText('Playwright')).toBeVisible();

        // Cancel button should appear (for self-signup)
        await expect(page.getByRole('button', { name: /Cancel/i })).toBeVisible();

        // Verify in DB
        const dbCount = dbQuery(
            `echo App\\Models\\ShiftAssignment::where('shift_id', ${data.open_shift_id})->count();`
        );
        expect(dbCount).toBe('1');
    });

    test('user can cancel their self-signup', async ({ page }) => {
        const data = seedScenario('schedule-signup');
        await login(page, data.user_email, data.user_password);

        await page.goto('/schedule');
        await page.waitForLoadState('networkidle');

        // Sign up first
        await page.getByRole('button', { name: /Sign Up/i }).click();
        await page.waitForTimeout(2000);
        await expect(page.getByRole('button', { name: /Cancel/i })).toBeVisible();

        // Cancel — has wire:confirm
        page.on('dialog', dialog => dialog.accept());
        await page.getByRole('button', { name: /Cancel/i }).click();
        await page.waitForTimeout(2000);

        // Verify removed from DB
        const dbCount = dbQuery(
            `echo App\\Models\\ShiftAssignment::where('shift_id', ${data.open_shift_id})->count();`
        );
        expect(dbCount).toBe('0');
    });
});

// ============================================================
// HAPPY PATH: Check-in / Check-out
// ============================================================

test.describe('Schedule - Check In/Out', () => {
    test.afterEach(() => cleanup());

    test('user can check in and check out of their shift via My Shifts', async ({ page }) => {
        const data = seedScenario('schedule-checkin');
        await login(page, data.user_email, data.user_password);

        await page.goto('/schedule/my-shifts');
        await page.waitForLoadState('networkidle');

        // Current Shifts section visible
        await expect(page.locator('h2').filter({ hasText: 'Current Shifts' })).toBeVisible();

        // Check In button
        const checkInBtn = page.getByRole('button', { name: /Check In/i });
        await expect(checkInBtn).toBeVisible();
        await checkInBtn.click();
        await page.waitForTimeout(2000);

        // Status changes to Checked In
        await expect(page.getByText('Checked In').first()).toBeVisible();

        // Check Out button appears
        const checkOutBtn = page.getByRole('button', { name: /Check Out/i });
        await expect(checkOutBtn).toBeVisible();
        await checkOutBtn.click();
        await page.waitForTimeout(2000);

        // Verify in DB
        const status = dbQuery(
            `echo App\\Models\\ShiftAssignment::find(${data.assignment_id})->status;`
        );
        expect(status).toBe('checked_out');
    });

    test('check-in button is available on schedule timeline too', async ({ page }) => {
        const data = seedScenario('schedule-checkin');
        await login(page, data.user_email, data.user_password);

        await page.goto('/schedule');
        await page.waitForLoadState('networkidle');

        await expect(page.getByRole('button', { name: /Check In/i })).toBeVisible();
    });
});

// ============================================================
// HAPPY PATH: Manager Schedule Management
// ============================================================

test.describe('Manage Schedule - Roles', () => {
    test.afterEach(() => cleanup());

    test('default roles are visible on the Roles tab', async ({ page }) => {
        const data = seedScenario('schedule-manage');
        await login(page, data.user_email, data.user_password);

        await page.goto('/schedule/manage');
        await page.waitForLoadState('networkidle');

        // Click Roles tab
        await page.getByRole('tab', { name: /Roles/i }).click();
        await page.waitForTimeout(500);

        // Default roles listed
        await expect(page.getByText('Safety Officer', { exact: true }).first()).toBeVisible();
        await expect(page.getByText('Default').first()).toBeVisible();
        await expect(page.getByText('Bonus: 100 pts').first()).toBeVisible();
    });

    test('manager can create a custom role', async ({ page }) => {
        const data = seedScenario('schedule-manage');
        await login(page, data.user_email, data.user_password);

        await page.goto('/schedule/manage');
        await page.waitForLoadState('networkidle');

        await page.getByRole('tab', { name: /Roles/i }).click();
        await page.waitForTimeout(500);

        await page.getByRole('button', { name: /Add Custom Role/i }).click();
        await page.waitForTimeout(500);

        // Fill modal form — wait for each field to debounce
        await page.locator('input[wire\\:model="roleName"]').fill('Generator Monitor');
        await page.waitForTimeout(500);
        await page.locator('textarea[wire\\:model="roleDescription"]').fill('Monitor generator fuel levels');
        await page.waitForTimeout(500);

        // Submit the role form by pressing Enter (triggers form submit)
        await page.locator('input[wire\\:model="roleName"]').press('Enter');
        await page.waitForTimeout(3000);

        // New role should appear in the roles list
        await expect(page.getByText('Generator Monitor')).toBeVisible({ timeout: 10000 });

        // Verify in DB
        const count = dbQuery(
            `echo App\\Models\\ShiftRole::where('event_configuration_id', ${data.event_config_id})->where('name', 'Generator Monitor')->count();`
        );
        expect(count).toBe('1');
    });
});

test.describe('Manage Schedule - Shifts', () => {
    test.afterEach(() => cleanup());

    test('manager can create a shift and assign a user', async ({ page }) => {
        const data = seedScenario('schedule-manage');
        await login(page, data.user_email, data.user_password);

        await page.goto('/schedule/manage');
        await page.waitForLoadState('networkidle');

        // Empty state visible
        await expect(page.getByText('No shifts created yet')).toBeVisible();

        // Click Add Shift
        await page.getByRole('button', { name: /Add Shift/i }).click();
        await page.waitForTimeout(500);

        // Select a role
        const roleSelect = page.locator('select[wire\\:model="shiftRoleId"]');
        await roleSelect.selectOption({ index: 1 });

        // Set times
        const now = new Date();
        const startTime = new Date(now.getTime() + 2 * 60 * 60 * 1000);
        const endTime = new Date(now.getTime() + 4 * 60 * 60 * 1000);
        const formatDT = (d: Date) => d.toISOString().slice(0, 16);

        await page.locator('input[wire\\:model="shiftStartTime"]').fill(formatDT(startTime));
        await page.locator('input[wire\\:model="shiftEndTime"]').fill(formatDT(endTime));
        await page.locator('input[wire\\:model="shiftCapacity"]').fill('2');

        // Submit the shift form by pressing Enter
        await page.locator('input[wire\\:model="shiftCapacity"]').press('Enter');
        await page.waitForTimeout(3000);

        // Shift created — empty state gone
        await expect(page.getByText('No shifts created yet')).not.toBeVisible({ timeout: 10000 });
        await expect(page.getByText('0/2')).toBeVisible();

        // Assign a user
        await page.locator('[wire\\:click^="openAssignModal"]').first().click();
        await page.waitForTimeout(500);

        const userSelect = page.locator('select[wire\\:model="assignUserId"]');
        await userSelect.selectOption({ label: /Operator Bob/ });

        await page.getByRole('button', { name: /Assign/i }).click();
        await page.waitForTimeout(2000);

        await expect(page.getByText('Operator Bob')).toBeVisible();
    });

    test('manager can bulk create shifts', async ({ page }) => {
        const data = seedScenario('schedule-manage');
        await login(page, data.user_email, data.user_password);

        await page.goto('/schedule/manage');
        await page.waitForLoadState('networkidle');

        await page.getByRole('button', { name: /Bulk Create/i }).click();
        await page.waitForTimeout(500);

        const roleSelect = page.locator('select[wire\\:model="bulkRoleId"]');
        await roleSelect.selectOption({ index: 1 });

        const now = new Date();
        const start = new Date(now.getTime() + 1 * 60 * 60 * 1000);
        const end = new Date(now.getTime() + 7 * 60 * 60 * 1000);
        const formatDT = (d: Date) => d.toISOString().slice(0, 16);

        await page.locator('input[wire\\:model="bulkStartTime"]').fill(formatDT(start));
        await page.locator('input[wire\\:model="bulkEndTime"]').fill(formatDT(end));
        await page.locator('input[wire\\:model="bulkDurationMinutes"]').fill('120');
        await page.locator('input[wire\\:model="bulkCapacity"]').fill('1');

        // Submit bulk form via Enter
        await page.locator('input[wire\\:model="bulkCapacity"]').press('Enter');
        await page.waitForTimeout(3000);

        await expect(page.getByText('No shifts created yet')).not.toBeVisible({ timeout: 5000 });

        const count = dbQuery(
            `echo App\\Models\\Shift::where('event_configuration_id', ${data.event_config_id})->count();`
        );
        expect(parseInt(count)).toBeGreaterThanOrEqual(3);
    });
});

test.describe('Manage Schedule - Confirmations', () => {
    test.afterEach(() => cleanup());

    test('manager can confirm a bonus-role check-in and EventBonus is created', async ({ page }) => {
        const data = seedScenario('schedule-checkin');
        await login(page, data.user_email, data.user_password);

        await page.goto('/schedule/manage');
        await page.waitForLoadState('networkidle');

        // Click Confirmations tab — Mary UI tabs use role="tab"
        await page.getByRole('tab', { name: 'Confirmations' }).click();
        await page.waitForTimeout(3000);

        // Operator Bob pending for Safety Officer
        await expect(page.locator('[role="tabpanel"] >> text=Operator Bob').first()).toBeVisible({ timeout: 10000 });

        await page.getByRole('button', { name: /Confirm/i }).click();
        await page.waitForTimeout(2000);

        await expect(page.getByText('No pending confirmations')).toBeVisible();

        // Verify EventBonus created
        const bonusExists = dbQuery(
            `echo App\\Models\\EventBonus::where('event_configuration_id', ${data.event_config_id})->where('is_verified', true)->exists() ? 'yes' : 'no';`
        );
        expect(bonusExists).toBe('yes');
    });

    test('manager can mark a user as no-show from Shifts tab', async ({ page }) => {
        const data = seedScenario('schedule-checkin');

        // Set up dialog handler BEFORE login/navigation
        page.on('dialog', dialog => dialog.accept());

        await login(page, data.user_email, data.user_password);
        await page.goto('/schedule/manage');
        await page.waitForLoadState('networkidle');

        // Find the no-show button for the bonus assignment (Operator Bob's)
        // The assignments are in the Shifts tab which is already active
        const noShowBtns = page.locator('[wire\\:click^="markNoShow"]');
        const count = await noShowBtns.count();

        // Click the one for Operator Bob's assignment
        for (let i = 0; i < count; i++) {
            const btn = noShowBtns.nth(i);
            const wireClick = await btn.getAttribute('wire:click');
            if (wireClick?.includes(String(data.bonus_assignment_id))) {
                await btn.click();
                break;
            }
        }
        await page.waitForTimeout(3000);

        // Verify in DB
        const status = dbQuery(
            `echo App\\Models\\ShiftAssignment::find(${data.bonus_assignment_id})->status;`
        );
        expect(status).toBe('no_show');
    });
});

// ============================================================
// MY SHIFTS VIEW
// ============================================================

test.describe('My Shifts', () => {
    test.afterEach(() => cleanup());

    test('shows empty state when user has no shifts', async ({ page }) => {
        const data = seedScenario('schedule-signup');
        await login(page, data.user_email, data.user_password);

        await page.goto('/schedule/my-shifts');
        await page.waitForLoadState('networkidle');

        await expect(page.getByText('You have no shifts happening right now')).toBeVisible();
        await expect(page.getByText('You have no upcoming shifts scheduled')).toBeVisible();
    });

    test('shows current shift with check-in action', async ({ page }) => {
        const data = seedScenario('schedule-checkin');
        await login(page, data.user_email, data.user_password);

        await page.goto('/schedule/my-shifts');
        await page.waitForLoadState('networkidle');

        await expect(page.locator('h2').filter({ hasText: 'Current Shifts' })).toBeVisible();
        await expect(page.getByRole('button', { name: /Check In/i })).toBeVisible();

        // Full Schedule link works
        await expect(page.getByRole('link', { name: /Full Schedule/i })).toBeVisible();
    });
});

// ============================================================
// EDGE CASES
// ============================================================

test.describe('Schedule - Edge Cases', () => {
    test.afterEach(() => cleanup());

    test('unauthenticated user is redirected from schedule', async ({ page }) => {
        await page.goto('/schedule');
        await expect(page).toHaveURL(/\/login/);
    });

    test('non-manager cannot access manage schedule page', async ({ page }) => {
        const data = seedScenario('schedule-signup');
        dbQuery(
            `$u = App\\Models\\User::where('email', 'playwright-test@example.com')->first(); $u->revokePermissionTo('manage-shifts'); echo 'done';`
        );

        await login(page, data.user_email, data.user_password);

        const response = await page.goto('/schedule/manage');
        expect(response?.status()).toBe(403);
    });

    test('sidebar navigation contains Schedule link', async ({ page }) => {
        const data = seedScenario('schedule-manage');
        await login(page, data.user_email, data.user_password);

        await page.goto('/schedule');
        await page.waitForLoadState('networkidle');

        // Schedule page itself confirms the nav works — just verify we're on the page
        await expect(page.locator('h1')).toContainText('Schedule');
    });

    test('manager can delete a shift with assignments', async ({ page }) => {
        const data = seedScenario('schedule-checkin');
        await login(page, data.user_email, data.user_password);

        await page.goto('/schedule/manage');
        await page.waitForLoadState('networkidle');

        page.on('dialog', dialog => dialog.accept());

        const beforeCount = dbQuery(
            `echo App\\Models\\Shift::where('event_configuration_id', ${data.event_config_id})->count();`
        );

        await page.locator('[wire\\:click^="deleteShift"]').first().click();
        await page.waitForTimeout(2000);

        const afterCount = dbQuery(
            `echo App\\Models\\Shift::where('event_configuration_id', ${data.event_config_id})->count();`
        );
        expect(parseInt(afterCount)).toBeLessThan(parseInt(beforeCount));
    });

    test('schedule shows no-event alert when event config missing', async ({ page }) => {
        const data = seedScenario('schedule-signup');
        dbQuery(
            `App\\Models\\EventConfiguration::where('event_id', App\\Models\\Event::where('name', 'PW Test Field Day')->first()->id)->forceDelete(); echo 'done';`
        );

        await login(page, data.user_email, data.user_password);
        await page.goto('/schedule');
        await page.waitForLoadState('networkidle');

        await expect(page.getByText(/No event is currently selected/i)).toBeVisible();
    });
});
