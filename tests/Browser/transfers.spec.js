import { test, expect } from '@playwright/test';

test.beforeEach(async ({ page }) => {
    page.on('dialog', dialog => dialog.accept());
    await page.goto('/login');
    await page.getByLabel('Email', { exact: true }).fill('warehouse-admin@example.test');
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

test('correcting warehouse validation synchronizes names and submitted IDs', async ({ page }) => {
    await page.goto('/transfers?status=pending&sort_by=quantity&sort_dir=asc&per_page=25');
    await page.getByRole('link', { name: '+ Új átmozgatás', exact: true }).click();
    await choose(page, 'Termék', 'ALPHA', 'Alpha teszttermék (ALPHA-001)');
    await choose(page, 'Célraktár', 'Teszt raktár', 'Teszt raktár · Test');
    const target = page.getByRole('combobox', { name: 'Célraktár', exact: true });
    const targetId = page.locator('input[name="to_warehouse_id"]');
    await expect(targetId).toHaveValue('1');
    await choose(page, 'Forrásraktár', 'Teszt raktár', 'Teszt raktár · Test');
    await expect(target).toHaveValue('');
    await expect(targetId).toHaveValue('');
    await target.fill('Teszt raktár');
    await expect(page.getByRole('option', { name: 'Teszt raktár · Test', exact: true })).toHaveCount(0);

    const form = page.getByRole('main').locator('form[action$="/transfers"]');
    const invalid = await page.request.post('/transfers', {
        headers: { Referer: page.url() }, maxRedirects: 0,
        form: {
            _token: await form.locator('input[name="_token"]').inputValue(),
            request_key: await form.locator('input[name="request_key"]').inputValue(),
            context: await form.locator('input[name="context"]').inputValue(),
            product_id: '1', from_warehouse_id: '1', to_warehouse_id: '1', quantity: '1',
        },
    });
    expect(invalid.status()).toBe(302);
    await page.goto(invalid.headers().location);
    const error = page.getByText('A forrásraktár és a célraktár nem lehet ugyanaz.', { exact: true });
    await expect(error).toBeVisible();
    await expect(target).toHaveValue('Teszt raktár · Test');
    await expect(target).toHaveAttribute('aria-invalid', 'true');

    await choose(page, 'Célraktár', 'Nyugati', 'Nyugati tesztraktár · Bemutató célállomás');
    await expect(error).toBeHidden();
    await expect(target).toHaveAttribute('aria-invalid', 'false');
    await expect(target).toHaveValue('Nyugati tesztraktár · Bemutató célállomás');
    await expect(page.getByRole('combobox', { name: 'Forrásraktár', exact: true })).toHaveValue('Teszt raktár · Test');
    await expect(targetId).toHaveValue('4');
    await expect(page.locator('input[name="from_warehouse_id"]')).toHaveValue('1');
    await expect(page.getByRole('link', { name: 'Mégse', exact: true })).toHaveAttribute('href', /status=pending/);
    const submitted = page.waitForRequest(request => request.method() === 'POST' && new URL(request.url()).pathname === '/transfers');
    await page.getByRole('button', { name: 'Átmozgatás indítása', exact: true }).click();
    const fields = new URLSearchParams((await submitted).postData());
    expect(fields.get('from_warehouse_id')).toBe('1');
    expect(fields.get('to_warehouse_id')).toBe('4');
    await expect(page.getByRole('status')).toContainText('Átmozgatás elindítva');
    await expect(page).toHaveURL(/status=pending/);
    expect(new URL(page.url()).searchParams.get('sort_by')).toBe('quantity');
    expect(new URL(page.url()).searchParams.get('per_page')).toBe('25');
});
