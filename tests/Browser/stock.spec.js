import { test, expect } from '@playwright/test';
import { randomUUID } from 'node:crypto';

async function login(page, admin = false) {
    await page.goto('/login');
    await page.getByLabel('Email', { exact: true }).fill(admin ? 'warehouse-admin@example.test' : 'browser@example.test');
    await page.getByLabel('Password', { exact: true }).fill('browser-test-password');
    await page.getByRole('button', { name: 'Log in', exact: true }).click();
    await expect(page).toHaveURL(/dashboard/);
}
const rows = page => page.locator('[data-stock-row]:visible');
async function editMinimum(row) {
    await row.getByRole('button', { name: /^Műveletek:/ }).click();
    await row.getByRole('menuitem', { name: 'Helyi minimum módosítása', exact: true }).click();
}

test('minimum editing opens from the menu on desktop and mobile and Cancel discards the draft and restores focus', async ({ page }) => {
    await login(page, true);
    await page.goto('/stock?product_id=1&warehouse_id=1');
    let writes = 0;
    page.on('request', request => { if (request.method() === 'POST' && new URL(request.url()).pathname.includes('/minimum')) writes++; });
    for (const width of [1440, 390]) {
        await page.setViewportSize({ width, height: 800 });
        const row = rows(page);
        const trigger = row.getByRole('button', { name: /^Műveletek:/ });
        await expect(row.getByRole('spinbutton')).toBeHidden();
        await trigger.click();
        const menu = row.getByRole('menu', { name: 'Készletsor műveletei', exact: true });
        await expect(menu.getByRole('menuitem')).toHaveCount(5);
        await expect(menu.getByRole('menuitem', { name: 'Törlés', exact: true })).toHaveCount(0);
        const box = await menu.boundingBox(); expect(box.x).toBeGreaterThanOrEqual(0); expect(box.x + box.width).toBeLessThanOrEqual(width);
        if (width === 390) await page.screenshot({ path: 'tests/Browser/.runtime/stock-actions-mobile.png' });
        await menu.getByRole('menuitem', { name: 'Helyi minimum módosítása', exact: true }).click();
        await expect(row.getByRole('spinbutton')).toBeFocused();
        await row.getByRole('spinbutton').fill('42');
        await row.getByRole('link', { name: 'Mégse', exact: true }).click();
        await expect(row.getByRole('spinbutton')).toBeHidden(); await expect(trigger).toBeFocused();
        await editMinimum(row); await expect(row.getByRole('spinbutton')).toHaveValue('5');
        await row.getByRole('link', { name: 'Mégse', exact: true }).click();
    }
    expect(writes).toBe(0);
});

test('row actions open a keyboard accessible menu and prefill a movement without changing stock', async ({ page }) => {
    await login(page);
    await page.goto('/stock?product_id=1&warehouse_id=1');
    const row = rows(page);
    const trigger = row.getByRole('button', { name: /^Műveletek:/ });
    const url = page.url();
    await trigger.focus(); await page.keyboard.press('ArrowDown');
    await expect(page).toHaveURL(url);
    const menu = row.getByRole('menu', { name: 'Készletsor műveletei', exact: true });
    await expect(menu).toBeVisible();
    await expect(menu.getByRole('menuitem')).toHaveCount(4);
    await expect(menu.getByRole('menuitem', { name: 'Készletmozgások', exact: true })).toBeFocused();
    await expect(menu.getByRole('menuitem', { name: /minimum|Törlés/ })).toHaveCount(0);
    await page.keyboard.press('Escape'); await expect(menu).toBeHidden(); await expect(trigger).toBeFocused();
    await trigger.click();
    const before = await (await page.request.get('/movements/stock?product_id=1&warehouse_id=1')).json();
    await menu.getByRole('menuitem', { name: 'Új készletmozgás', exact: true }).click();
    await expect(page.getByRole('combobox', { name: 'Termék', exact: true })).toHaveValue('Alpha teszttermék (ALPHA-001)');
    await expect(page.getByRole('combobox', { name: 'Raktár', exact: true })).toHaveValue('Teszt raktár · Test');
    await expect(page.getByRole('status')).toContainText(before.quantity+' db');
    const after = await (await page.request.get('/movements/stock?product_id=1&warehouse_id=1')).json();
    expect(after).toEqual(before);
});

async function post(page, path, data) {
    return page.request.post(path, { headers: { 'X-CSRF-TOKEN': await page.locator('meta[name="csrf-token"]').getAttribute('content') }, data, maxRedirects: 0 });
}
async function product(page, prefix = 'Készlet teszt', long = false) {
    const sku = randomUUID()+(long ? 'W'.repeat(14) : '');
    const name = (prefix+' '+sku).padEnd(long ? 100 : 0, 'W').slice(0, 100);
    expect((await post(page, '/products', { product_name: name, sku, price: 1, category_id: 1, min_stock_override: 0 })).status()).toBe(302);
    const html = await (await page.request.get('/products?search='+sku)).text();
    return { id: Number(html.match(/href="[^"]*\/products\/(\d+)\/edit/)[1]), sku, name };
}
async function movement(page, id, quantity, type = 'in', warehouse = 1) {
    const reference = 'STOCK-'+randomUUID();
    expect((await post(page, '/movements', { request_key: randomUUID(), product_id: id, warehouse_id: warehouse, quantity, type, reference })).status()).toBe(302);
    const html = await (await page.request.get('/movements?search='+reference)).text();
    return Number(html.match(/href="[^"]*\/movements\/(\d+)(?:\?|\")/)[1]);
}

test('suggestions choose an exact product, editing clears it, and heading sorting submits the current search', async ({ page }) => {
    const errors = []; page.on('pageerror', error => errors.push(error.message));
    await login(page);
    await page.goto('/stock?warehouse_id=1');
    const input = page.getByRole('combobox', { name: 'Készlet keresése', exact: true });
    await input.fill('ALPHA-001');
    await page.getByRole('option').filter({ hasText: 'ALPHA-001' }).click();
    await expect(page).toHaveURL(url => url.searchParams.get('product_id') === '1' && !url.searchParams.has('search') && url.searchParams.get('warehouse_id') === '1');
    await expect(rows(page)).toHaveCount(1);
    await input.fill('ZETA-002');
    await expect(page.locator('#stock-filters input[name="product_id"]')).toHaveValue('');
    await page.getByRole('columnheader', { name: 'Kiadható', exact: false }).getByRole('link').click();
    await expect(page).toHaveURL(url => url.searchParams.get('search') === 'ZETA-002' && url.searchParams.get('sort_by') === 'available' && url.searchParams.get('sort_dir') === 'asc');
    await expect(rows(page)).toHaveCount(1); await expect(rows(page)).toContainText('Zeta teszttermék');
    await expect(page.getByRole('columnheader', { name: 'Kiadható', exact: false })).toHaveAttribute('aria-sort', 'ascending');
    await page.getByRole('columnheader', { name: 'Kiadható', exact: false }).getByRole('link').click();
    await expect(page).toHaveURL(url => url.searchParams.get('sort_dir') === 'desc');
    await page.getByLabel('Raktár', { exact: true }).selectOption('4');
    await expect(page.getByRole('status')).toContainText('Nincs a feltételeknek megfelelő készletsor.');
    await expect(page.locator('input[name="expected_minimum"]')).toHaveCount(0);
    expect(errors).toEqual([]);
});

test('pagination and minimum editing preserve search filters sorting and page size', async ({ page }) => {
    test.setTimeout(60000);
    await login(page, true);
    const batch = 'BATCH-'+randomUUID().slice(0, 8);
    for (let i = 1; i <= 12; i++) { const item = await product(page, batch); await movement(page, item.id, i); }
    await page.goto('/stock?search='+batch+'&warehouse_id=1&per_page=10&sort_by=quantity&sort_dir=desc');
    await expect(rows(page)).toHaveCount(10);
    await expect(page.locator('[data-stock-summary="physical"]')).toContainText('78');
    await page.getByRole('link', { name: 'Következő', exact: true }).click();
    await expect(rows(page)).toHaveCount(2);
    const row = rows(page).first();
    const id = await row.getAttribute('data-stock-row');
    await editMinimum(row);
    await row.getByRole('spinbutton').fill('14');
    await row.getByRole('button', { name: 'Mentés', exact: true }).click();
    await expect(page.getByRole('status')).toContainText('A helyi minimum frissítve');
    await expect(page).toHaveURL(url => url.searchParams.get('page') === '2' && url.searchParams.get('search') === batch && url.searchParams.get('warehouse_id') === '1' && url.searchParams.get('per_page') === '10' && url.searchParams.get('sort_by') === 'quantity' && url.searchParams.get('sort_dir') === 'desc');
    await expect(page.locator(`[data-stock-row="${id}"]:visible [data-stock-minimum]`)).toContainText('14 db');
    await page.getByLabel('Készletsorok oldalanként', { exact: true }).selectOption('25');
    await expect(page).toHaveURL(url => !url.searchParams.has('page') && url.searchParams.get('per_page') === '25');
    await expect(rows(page)).toHaveCount(12);
});

test('stale minimum edits fail clearly and do not overwrite another users saved minimum', async ({ page, context }) => {
    await login(page, true);
    const item = await product(page); await movement(page, item.id, 9);
    await page.goto('/stock?product_id='+item.id);
    const other = await context.newPage();
    try {
        await other.goto('/stock?product_id='+item.id);
        for (const tab of [page, other]) await editMinimum(rows(tab));
        await rows(page).getByRole('spinbutton').fill('12');
        await rows(page).getByRole('button', { name: 'Mentés', exact: true }).click();
        await rows(other).getByRole('spinbutton').fill('3');
        await rows(other).getByRole('button', { name: 'Mentés', exact: true }).click();
        await expect(other.getByRole('alert')).toContainText('A helyi minimum közben megváltozott');
        await expect(rows(other).locator('[data-stock-minimum]')).toContainText('12 db');
        await expect(rows(other).getByRole('spinbutton')).toHaveValue('3');
        const stock = await (await other.request.get('/movements/stock?product_id='+item.id+'&warehouse_id=1')).json();
        expect(stock.physical_quantity).toBe(9);
    } finally { await other.close(); }
});

test('fully quarantined stock is unavailable and its action opens the matching quality queue', async ({ page }) => {
    await login(page);
    const item = await product(page); await movement(page, item.id, 6);
    const issue = await movement(page, item.id, 6, 'out');
    expect((await post(page, '/movements/'+issue+'/return', { request_key: randomUUID(), quantity: 6, return_reason: 'Stock listing test' })).status()).toBe(302);
    await page.goto('/stock?product_id='+item.id+'&stock_status=out');
    await expect(rows(page)).toHaveCount(1);
    await expect(page.locator('[data-stock-summary="available"]')).toContainText('0');
    await expect(page.locator('[data-stock-summary="quarantined"]')).toContainText('6');
    await rows(page).locator('[data-quality-link]').click();
    await expect(page).toHaveURL(url => url.pathname === '/quality-returns' && url.searchParams.get('product_id') === String(item.id) && url.searchParams.get('warehouse_id') === '1');
});

test('mobile cards and sorting fit narrow screens and enlarged text with long names and large quantities', async ({ page }) => {
    await login(page, true);
    const item = await product(page, 'Hosszú terméknév', true); await movement(page, item.id, 2147483647);
    await page.goto('/stock?product_id='+item.id);
    for (const width of [320, 390, 768, 1280, 1440]) {
        await page.setViewportSize({ width, height: 800 });
        const overflow = await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth ? [] : [...document.querySelectorAll('main *')].filter(element => element.getBoundingClientRect().right > window.innerWidth + 1).slice(0, 12).map(element => ({ tag: element.tagName, classes: element.className, width: element.getBoundingClientRect().width })));
        expect(overflow, 'Overflow at '+width+'px').toEqual([]);
        await expect(rows(page)).toHaveCount(1);
    }
    await page.setViewportSize({ width: 320, height: 740 });
    await page.screenshot({ path: 'tests/Browser/.runtime/stock-mobile.png', fullPage: true });
    await page.evaluate(() => { document.documentElement.style.fontSize = '24px'; });
    await editMinimum(rows(page));
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
    await page.screenshot({ path: 'tests/Browser/.runtime/stock-mobile-large-text.png', fullPage: true });
    await page.evaluate(() => { document.documentElement.style.fontSize = ''; });
    await page.locator('summary').filter({ hasText: /^Rendezés:/ }).click();
    await page.locator('[data-stock-card-sort]').getByRole('link', { name: 'Kiadható', exact: false }).click();
    await expect(page).toHaveURL(url => url.searchParams.get('sort_by') === 'available');
    await page.setViewportSize({ width: 1440, height: 1000 });
    await page.screenshot({ path: 'tests/Browser/.runtime/stock-desktop.png', fullPage: true });
});

test('native filters mobile sorting and minimum save work without JavaScript', async ({ browser }) => {
    const context = await browser.newContext({ javaScriptEnabled: false, viewport: { width: 390, height: 800 } });
    const page = await context.newPage();
    try {
        await login(page, true);
        const item = await product(page); await movement(page, item.id, 2);
        await page.goto('/stock?product_id='+item.id+'&warehouse_id=1&per_page=10');
        await page.getByLabel('Készletállapot', { exact: true }).selectOption('low');
        await page.getByRole('button', { name: 'Szűrés alkalmazása', exact: true }).click();
        await expect(rows(page)).toHaveCount(1);
        await page.locator('summary').filter({ hasText: /^Rendezés:/ }).click();
        await page.locator('[data-stock-card-sort]').getByRole('link', { name: 'Kiadható', exact: false }).click();
        await expect(page).toHaveURL(url => url.searchParams.get('sort_by') === 'available' && url.searchParams.get('stock_status') === 'low');
        await editMinimum(rows(page));
        await rows(page).getByRole('spinbutton').fill('8');
        await rows(page).getByRole('button', { name: 'Mentés', exact: true }).click();
        await expect(page.getByRole('status')).toContainText('A helyi minimum frissítve');
        await expect(page).toHaveURL(url => url.searchParams.get('product_id') === String(item.id) && url.searchParams.get('stock_status') === 'low' && url.searchParams.get('per_page') === '10');
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
    } finally { await context.close(); }
});
