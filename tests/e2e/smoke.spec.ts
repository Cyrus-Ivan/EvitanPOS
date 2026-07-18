import { expect, test } from '@playwright/test';
import { addToCart, login, toast } from './helpers';

/**
 * S6 smoke: the app boots, a cashier can log in, see products, complete a
 * sale, and log out. Deeper per-role workflows live in the S7 specs.
 */

test('login lands on the POS screen with the product grid', async ({ page }) => {
    await login(page, 'cashier');

    await expect(page.getByPlaceholder(/^Search or scan/)).toBeVisible();

    // The seeded fixture product is sellable and visible via search.
    await page.getByPlaceholder(/^Search or scan/).fill('E2E Smoke Coffee');
    await expect(page.getByRole('button', { name: /E2E Smoke Coffee/ })).toBeVisible();
});

test('a cash checkout completes and shows the success toast', async ({ page }) => {
    await login(page, 'cashier');
    await addToCart(page, 'E2E Smoke Coffee');

    await page.locator('#cash-received').fill('100');

    const checkout = page.getByRole('button', { name: /Checkout ·/ });
    await expect(checkout).toBeEnabled();
    await checkout.click();

    await expect(toast(page)).toContainText(/completed/i);
});

test('logout returns to the login screen', async ({ page }) => {
    await login(page, 'cashier');

    // The sidebar user menu (name + chevron) opens the dropdown with Log out.
    await page.getByRole('button', { name: /E2E Cashier/ }).click();
    await page.getByRole('menuitem', { name: /Log out/ }).click();

    await page.waitForURL('**/login');
    await expect(page.locator('#username')).toBeVisible();
});
