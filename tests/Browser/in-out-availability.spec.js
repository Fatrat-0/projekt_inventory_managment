import { expect, test } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { randomUUID } from 'node:crypto';
import { shipmentCommand } from './shipment-fixtures';
import { seedUsableStock } from './movement-fixtures';

test('stock and movement screens separate the protected receipt from direct issue on desktop and mobile', async ({ page }) => {
    const shipment = JSON.parse(execFileSync('php', ['tests/Browser/prepare-shipment-receiving.php'], { encoding: 'utf8' }));
    const product = shipment.products.screw;
    const state = JSON.parse(execFileSync('php', ['tests/Browser/read-shipment-receiving.php', String(shipment.id)], { encoding: 'utf8' }));
    const transit = state.transit.find(row => row.product_id === product.id);
    await page.goto('/login');
    await page.getByLabel('Email', { exact: true }).fill('warehouse-admin@example.test');
    await page.getByLabel('Password', { exact: true }).fill('browser-test-password');
    await page.getByRole('button', { name: 'Log in' }).click();
    await expect(page).toHaveURL(/dashboard/);
    const params = new URLSearchParams({ product_id: product.id, warehouse_id: product.warehouse });
    seedUsableStock({ product_id: product.id, warehouse_id: product.warehouse, quantity: 50 });
    await shipmentCommand(page, 'POST', '/shipment-plans/' + shipment.id + '/stops/' + shipment.pecs.id + '/receipts', {
        operation_key: randomUUID(), version: 1, confirmed: true, items: [{ transit_item_id: transit.id, quantity: 200 }],
    }, 201);

    for (const width of [1440, 320]) {
        await page.setViewportSize({ width, height: 800 });
        await page.goto('/movements/create?' + params);
        await page.getByLabel('Mozgás típusa').selectOption('out');
        const balance = page.getByRole('status', { name: 'Készletelérhetőség' });
        for (const [label, quantity] of [['Fizikai készlet', '250 db'], ['Közvetlenül kiadható', '50 db'], ['Kitárolható összesen', '50 db']]) {
            await expect(balance.locator('div').filter({ has: page.getByText(label, { exact: true }) }).locator('dd')).toHaveText(quantity);
        }
        await page.getByText('Készletrészletek', { exact: true }).click();
        await expect(page.getByText('Betárolásra vár', { exact: true })).toBeVisible();
        await expect(page.getByText('200 db', { exact: true })).toBeVisible();
        await page.goto('/stock?' + params);
        const row = page.locator('[data-stock-row]').filter({ visible: true });
        await expect(row).toContainText('250 db');
        await expect(row.locator('[data-direct-issue]')).toHaveText('50 db');
        await row.getByText('Készletrészletek', { exact: true }).click();
        await expect(row.getByText('Betárolásra vár', { exact: true })).toBeVisible();
        await expect(row.getByText('200 db', { exact: true })).toBeVisible();
        expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(width);

        await page.goto('/movements?' + params);
        await page.getByLabel('Mozgás eredete', { exact: true }).selectOption('shipment_receipt');
        await page.getByRole('button', { name: 'Szűrés alkalmazása', exact: true }).click();
        await expect(page).toHaveURL(url => url.searchParams.get('origin') === 'shipment_receipt');
        const movement = page.locator('[data-movement-id]').filter({ visible: true });
        await expect(movement).toHaveCount(1);
        await expect(movement).toContainText('Fuvarátvétel');
        await movement.getByRole('link', { name: /^MOZ-/ }).click();
        await expect(page.getByRole('link', { name: 'Átvételi munkalap megnyitása' })).toHaveAttribute('href', new RegExp('/atvetel/' + shipment.id + '/' + shipment.pecs.id + '$'));
    }
});
