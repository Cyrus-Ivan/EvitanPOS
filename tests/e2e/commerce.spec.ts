import { expect, test } from '@playwright/test';
import { addToCart, login, logout, parsePeso, statValue, toast } from './helpers';

/**
 * S7 commerce chain: sell with a discount → partial return → defect → the
 * report recomputes to exactly the fixture arithmetic.
 *
 * Fixture (product ₱25.00 / cost ₱10.00): sell 4 with 10% discount → total
 * 90.00; return 1 → refund round(9000¢ / 4) = 22.50; defect 1 → 22.50 write-off.
 * Report deltas: gross +90.00, returns +22.50, defects +22.50, net +45.00,
 * profit +45.00 − (sold cost 40.00 − returned cost 10.00) = +15.00.
 *
 * Assertions are DELTAS against the pre-flow report so the spec stays exact
 * under accumulated state (smoke checkouts, repeated local runs).
 */

test('sell → partial return → defect → report recomputes exactly', async ({ page }) => {
    test.setTimeout(90_000);

    // Baseline report numbers (Owner sees profit).
    await login(page, 'owner');
    await page.goto('/reports');
    const before = {
        gross: parsePeso(await statValue(page, 'Gross sales')),
        returns: parsePeso(await statValue(page, 'Returns')),
        defects: parsePeso(await statValue(page, 'Defects')),
        net: parsePeso(await statValue(page, 'Net sales')),
        profit: parsePeso(await statValue(page, 'Gross profit')),
    };
    await logout(page, 'E2E Owner');

    // Sell 4 units with a 10% discount, as the admin (defects need Admin+).
    await login(page, 'admin');
    for (let i = 0; i < 4; i++) {
        await addToCart(page, 'E2E Smoke Coffee');
    }

    await page.getByLabel('Percentage discount').click();
    await page.getByLabel('Discount value').fill('10');
    await page.locator('#cash-received').fill('200');
    await page.getByRole('button', { name: /Checkout ·/ }).click();
    await expect(toast(page)).toContainText(/completed/i);

    // Open the sale's detail page (newest row on top).
    await page.goto('/sales');
    await page.getByRole('row').nth(1).click();
    await page.waitForURL(/\/sales\/[0-9a-f-]+$/);
    await expect(page.getByText('₱90.00').first()).toBeVisible();

    // Partial return of 1 unit → ₱22.50 refunded.
    await page.getByRole('button', { name: 'Return', exact: true }).click();
    const returnDialog = page.locator('[role="dialog"][data-state="open"]');
    await returnDialog.locator('input[type=number]').fill('1');
    await returnDialog.locator('#refund-method').click();
    await page.getByRole('option', { name: 'Cash', exact: true }).click();
    await returnDialog.getByRole('button', { name: 'Process return' }).click();
    await expect(toast(page)).toContainText(/return/i);
    await expect(page.getByText('₱22.50').first()).toBeVisible();

    // Defect of 1 unit (no refund method — money is not returned).
    await page.getByRole('button', { name: 'Defect', exact: true }).click();
    const defectDialog = page.locator('[role="dialog"][data-state="open"]');
    await defectDialog.locator('input[type=number]').fill('1');
    await defectDialog.getByRole('button', { name: 'Record defect' }).click();
    await expect(toast(page)).toContainText(/defect/i);

    await logout(page, 'E2E Admin');

    // The report must recompute to exactly the fixture arithmetic.
    await login(page, 'owner');
    await page.goto('/reports');

    expect(parsePeso(await statValue(page, 'Gross sales'))).toBeCloseTo(before.gross + 90.0, 2);
    expect(parsePeso(await statValue(page, 'Returns'))).toBeCloseTo(before.returns + 22.5, 2);
    expect(parsePeso(await statValue(page, 'Defects'))).toBeCloseTo(before.defects + 22.5, 2);
    expect(parsePeso(await statValue(page, 'Net sales'))).toBeCloseTo(before.net + 45.0, 2);
    expect(parsePeso(await statValue(page, 'Gross profit'))).toBeCloseTo(before.profit + 15.0, 2);
});
