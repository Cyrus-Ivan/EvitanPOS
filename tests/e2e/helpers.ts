import { expect, type Page } from '@playwright/test';

export type Role = 'owner' | 'admin' | 'cashier';

const USERNAMES: Record<Role, string> = {
    owner: 'owner',
    admin: 'e2e-admin',
    cashier: 'e2e-cashier',
};

/** Log in through the real form and wait until the POS screen is up. */
export async function login(page: Page, role: Role): Promise<void> {
    await page.goto('/login');
    await page.locator('#username').fill(USERNAMES[role]);
    await page.locator('#password').fill('password');
    await page.locator('button[type=submit]').click();
    await page.waitForURL('**/pos');
}

/** Search the POS grid and add the matching product tile to the cart. */
export async function addToCart(page: Page, label: string): Promise<void> {
    const search = page.getByPlaceholder(/^Search or scan/);
    await search.fill(label);

    const tile = page.getByRole('button', { name: new RegExp(label) });
    await expect(tile).toBeVisible();
    await tile.click();
}

/** The Sonner toast container holding the latest flash message. */
export function toast(page: Page) {
    return page.locator('[data-sonner-toast]').last();
}
