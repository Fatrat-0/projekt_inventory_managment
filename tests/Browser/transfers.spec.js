import { test, expect } from '@playwright/test';

test.beforeEach(async ({ page }) => {
    page.on('dialog', dialog => dialog.accept());
    await page.goto('/login');
    await page.getByLabel('Email', { exact: true }).fill('browser@example.test');
    await page.getByLabel('Password', { exact: true }).fill('browser-test-password');
    await page.getByLabel('Password', { exact: true }).press('Enter');
    await expect(page).toHaveURL(/dashboard/);
});
async function choose(page, label, query, option) {
    await page.getByRole('combobox', { name: label, exact: true }).fill(query);
    await page.getByRole('option', { name: option, exact: true }).click();
}
test('single transfer uses suggestions, stock lookup, replay protection and receipt', async ({ page }) => {
    const before = (await (await page.request.get('/movements/stock?product_id=1&warehouse_id=1')).json()).quantity;
    const targetBefore = (await (await page.request.get('/movements/stock?product_id=1&warehouse_id=2')).json()).quantity;
    await page.goto('/transfers/create');
    await choose(page, 'Termék', 'ALPHA', 'Alpha teszttermék (ALPHA-001)');
    await choose(page, 'Forrásraktár', 'Teszt raktár', 'Teszt raktár · Test');
    await choose(page, 'Célraktár', 'Északi', 'Északi tesztraktár · Bemutató célállomás');
    await expect(page.getByRole('status')).toContainText(before+' db');
    const key = await page.locator('input[name="request_key"]').inputValue();
    const csrf = await page.getByRole('main').locator('input[name="_token"]').inputValue();
    await page.getByRole('button', { name: 'Átmozgatás indítása', exact: true }).click();
    await expect(page.getByRole('status')).toContainText('Átmozgatás elindítva: ATM-');
    const replay = await page.request.post('/transfers', { form: { _token: csrf, request_key: key, product_id: '1', from_warehouse_id: '1', to_warehouse_id: '2', quantity: '1' } });
    expect(replay.ok()).toBe(true);
    const stock = await page.request.get('/movements/stock?product_id=1&warehouse_id=1');
    expect((await stock.json()).quantity).toBe(before - 1);
    const row = page.getByRole('row').filter({ has: page.getByRole('link', { name: 'Alpha teszttermék', exact: true }) }).first();
    await row.getByRole('button', { name: 'Átvétel', exact: true }).click();
    await expect(page.getByRole('status')).toContainText('Az átvétel rögzítve');
    const target = await page.request.get('/movements/stock?product_id=1&warehouse_id=2');
    expect((await target.json()).quantity).toBe(targetBefore + 1);
});

test('transfer validation preserves selections and cancel retains list context', async ({ page }) => {
    await page.goto('/transfers?status=pending&sort_by=quantity&sort_dir=asc&per_page=25');
    await page.getByRole('link', { name: '+ Új átmozgatás', exact: true }).click();
    await choose(page, 'Termék', 'ALPHA', 'Alpha teszttermék (ALPHA-001)');
    await choose(page, 'Forrásraktár', 'Teszt raktár', 'Teszt raktár · Test');
    await choose(page, 'Célraktár', 'Teszt raktár', 'Teszt raktár · Test');
    await page.getByRole('button', { name: 'Átmozgatás indítása', exact: true }).click();
    await expect(page.getByRole('alert')).toBeVisible();
    await expect(page.getByRole('combobox', { name: 'Forrásraktár', exact: true })).toHaveValue('Teszt raktár · Test');
    await expect(page.getByRole('combobox', { name: 'Célraktár', exact: true })).toHaveAttribute('aria-invalid', 'true');
    await page.getByRole('link', { name: 'Mégse', exact: true }).click();
    await expect(page).toHaveURL(/status=pending/);
    expect(new URL(page.url()).searchParams.get('sort_by')).toBe('quantity');
    expect(new URL(page.url()).searchParams.get('per_page')).toBe('25');
});
