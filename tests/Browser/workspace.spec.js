import { test, expect } from '@playwright/test';
import { randomUUID } from 'node:crypto';

async function login(page, admin = false) {
    await page.goto('/login');
    await page.getByLabel('Email', { exact: true }).fill(admin ? 'warehouse-admin@example.test' : 'browser@example.test');
    await page.getByLabel('Password', { exact: true }).fill('browser-test-password');
    await page.getByRole('button', { name: 'Log in', exact: true }).click();
    await expect(page).toHaveURL(/dashboard/);
}
const dialog = page => page.getByRole('dialog', { name: 'Gyorskereső', exact: true });
const input = page => dialog(page).getByRole('combobox', { name: 'Oldal vagy termék keresése', exact: true });
async function post(page, url, data) {
    return page.request.post(url, { headers: { 'X-CSRF-TOKEN': await page.locator('meta[name="csrf-token"]').getAttribute('content') }, data, maxRedirects: 0 });
}

test('list pages use the full desktop workspace and collapsed sidebar space while forms remain readable', async ({ page }) => {
    await login(page, true);
    for (const width of [1920, 2560]) {
        await page.setViewportSize({ width, height: 1000 });
        for (const path of ['/dashboard', '/stock', '/products', '/movements', '/categories', '/warehouses', '/partners', '/transfers', '/shipments', '/users', '/quality-returns']) {
            await page.goto(path);
            const outer = await page.locator('#main-content .app-page').first().boundingBox();
            expect(outer.x, path).toBe(240); expect(outer.x + outer.width, path).toBe(width);
            await expect(page.locator('[data-page-toolbar]').first()).toBeVisible();
        }
    }
    await page.goto('/stock');
    await page.getByRole('button', { name: 'Oldalsáv elrejtése', exact: true }).click();
    const box = await page.locator('#main-content .app-page').boundingBox();
    expect(box.x).toBe(0); expect(box.width).toBe(2560);
    await page.getByRole('button', { name: 'Oldalsáv megjelenítése', exact: true }).click();
    await page.setViewportSize({ width: 1920, height: 1000 });
    await page.screenshot({ path: 'tests/Browser/.runtime/workspace-stock-wide.png', fullPage: true });
    for (const path of ['/movements/create', '/transfers/create', '/partners/create']) {
        await page.goto(path);
        expect((await page.locator('#main-content form').first().boundingBox()).width).toBeLessThan(1000);
    }
});

test('global search shortcuts preserve the current draft and keyboard navigation opens a real product', async ({ page }) => {
    const errors = []; page.on('pageerror', error => errors.push(error.message));
    await login(page); await page.goto('/stock');
    const local = page.getByRole('combobox', { name: 'Készlet keresése', exact: true });
    await local.fill('Megmaradó keresés');
    await page.keyboard.press('Control+k'); await expect(dialog(page)).toBeVisible(); await expect(input(page)).toBeFocused();
    await expect(dialog(page).getByRole('option')).toHaveCount(6);
    await expect(dialog(page).getByRole('link', { name: /Felhasználók/ })).toHaveCount(0);
    await expect(page.locator('body')).toHaveCSS('overflow', 'hidden');
    await page.keyboard.press('Control+k'); await expect(input(page)).toBeFocused();
    await page.keyboard.press('Escape'); await expect(dialog(page)).toBeHidden(); await expect(local).toBeFocused();
    await expect(local).toHaveValue('Megmaradó keresés'); await expect(page.locator('body')).not.toHaveCSS('overflow', 'hidden');
    await page.getByRole('link', { name: 'Globális gyorskereső', exact: true }).click();
    await input(page).fill('ALPHA-001');
    await expect(dialog(page).getByRole('option')).toHaveCount(1);
    await expect(dialog(page)).toContainText('Alpha teszttermék');
    await input(page).press('ArrowDown'); await input(page).press('Enter');
    await expect(page).toHaveURL(url => url.pathname === '/products/1');
    expect(errors).toEqual([]);
});

test('search ignores old responses and recovers from failure and closing during a request', async ({ page }) => {
    await login(page);
    let release, entered, finished;
    const gate = new Promise(resolve => { release = resolve; });
    const started = new Promise(resolve => { entered = resolve; });
    const completed = new Promise(resolve => { finished = resolve; });
    let fail = true;
    await page.route('**/search?**', async route => {
        const q = new URL(route.request().url()).searchParams.get('q');
        if (q === 'slow') { entered(); await gate; }
        try {
            if (q === 'failure' && fail) { fail = false; await route.fulfill({ status: 503, body: 'Unavailable' }); }
            else await route.fulfill({ json: { results: [{ label: q === 'slow' ? 'Régi találat' : 'Új találat', detail: 'Teszt', kind: 'Oldal', url: '/stock' }], moreUrl: null } });
        } finally { if (q === 'slow') finished(); }
    });
    try {
        await page.keyboard.press('Control+k');
        await input(page).fill('slow'); await started;
        await input(page).fill('fast'); await expect(dialog(page).getByRole('option')).toContainText('Új találat');
        release(); await completed; await expect(dialog(page).getByRole('option')).toContainText('Új találat');
        await input(page).fill('failure'); await expect(dialog(page).getByRole('alert')).toContainText('A keresés nem sikerült');
        await dialog(page).getByRole('button', { name: 'Újrapróbálás', exact: true }).click();
        await expect(dialog(page).getByRole('option')).toContainText('Új találat');
        await input(page).fill('reopen'); await page.keyboard.press('Escape');
        await page.keyboard.press('Control+k');
        await expect(dialog(page).getByRole('option')).toContainText('Új találat');
        await page.keyboard.press('Escape');
        await expect(page.locator('body')).not.toHaveCSS('overflow', 'hidden');
    } finally { release(); await page.unroute('**/search?**'); }
});

test('task indicator uses real pending transfers, supports keyboard and closes before opening search', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 800 });
    await login(page);
    const sku = randomUUID();
    expect((await post(page, '/products', { product_name: 'Feladat teszt', sku, category_id: 1, price: 1, min_stock_override: 0 })).status()).toBe(302);
    const html = await (await page.request.get('/products?search='+sku)).text();
    const product = Number(html.match(/href="[^"]*\/products\/(\d+)\/edit/)[1]);
    expect((await post(page, '/movements', { request_key: randomUUID(), product_id: product, warehouse_id: 1, quantity: 5, type: 'in' })).status()).toBe(302);
    expect((await post(page, '/transfers', { request_key: randomUUID(), product_id: product, from_warehouse_id: 1, to_warehouse_id: 2, quantity: 1 })).status()).toBe(302);
    await page.reload();
    const trigger = page.getByRole('button', { name: /^Nyitott feladatok,/ });
    await expect(trigger).not.toHaveAttribute('aria-label', 'Nyitott feladatok, 0 tétel');
    await trigger.focus(); await page.keyboard.press('ArrowDown');
    const menu = page.getByRole('menu', { name: 'Nyitott feladatok', exact: true });
    await expect(menu).toBeVisible(); await expect(menu.getByRole('menuitem').first()).toBeFocused();
    const rect = await menu.boundingBox(); expect(rect.x).toBeGreaterThanOrEqual(0); expect(rect.x + rect.width).toBeLessThanOrEqual(390);
    await page.keyboard.press('Control+k'); await expect(menu).toBeHidden(); await expect(dialog(page)).toBeVisible();
    await page.keyboard.press('Escape');
    await trigger.click(); await menu.getByRole('menuitem', { name: /Átvételre váró átmozgatások/ }).click();
    await expect(page).toHaveURL(url => url.pathname === '/transfers' && url.searchParams.get('status') === 'pending');
    const listing = await (await page.request.get('/transfers?status=pending&product_id='+product)).text();
    const id = listing.match(/href="[^"]*\/transfers\/(\d+)(?:\?|\"|\/pdf)/)[1];
    expect((await post(page, '/transfers/'+id+'/cancel', { _method: 'PATCH' })).status()).toBe(302);
});

test('topbar and dialogs fit narrow screens with large text and do not compete with the mobile menu', async ({ page }) => {
    const errors = []; page.on('pageerror', error => errors.push(error.message));
    await login(page, true);
    await page.setViewportSize({ width: 320, height: 740 }); await page.goto('/dashboard/customize');
    await page.evaluate(() => { document.documentElement.style.fontSize = '24px'; });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
    const trigger = page.getByRole('button', { name: 'Navigációs menü', exact: true });
    await trigger.click(); await page.keyboard.press('Control+k');
    await expect(page.getByRole('dialog', { name: 'Készletkezelő', exact: true })).toBeVisible(); await expect(dialog(page)).toBeHidden();
    await page.keyboard.press('Escape'); await page.getByRole('link', { name: 'Globális gyorskereső', exact: true }).click();
    await expect(input(page)).toBeFocused();
    const box = await dialog(page).boundingBox(); expect(box.x).toBeGreaterThanOrEqual(0); expect(box.x + box.width).toBeLessThanOrEqual(320); expect(box.height).toBeLessThanOrEqual(740);
    await page.screenshot({ path: 'tests/Browser/.runtime/workspace-search-mobile.png' });
    await page.keyboard.press('Escape');
    await page.getByRole('button', { name: /^Nyitott feladatok,/ }).click();
    const menu = await page.getByRole('menu', { name: 'Nyitott feladatok', exact: true }).boundingBox();
    expect(menu.x).toBeGreaterThanOrEqual(0); expect(menu.x + menu.width).toBeLessThanOrEqual(320);
    await page.keyboard.press('Escape'); await page.evaluate(() => { document.documentElement.style.fontSize = ''; });
    await page.goto('/stock'); await page.screenshot({ path: 'tests/Browser/.runtime/workspace-stock-mobile.png', fullPage: true });
    expect(errors).toEqual([]);
});

test('full search and task navigation remain useful without JavaScript', async ({ browser }) => {
    const context = await browser.newContext({ javaScriptEnabled: false, viewport: { width: 320, height: 740 } });
    try {
        const page = await context.newPage(); await login(page);
        await page.getByRole('link', { name: 'Globális gyorskereső', exact: true }).click();
        await expect(page).toHaveURL(/\/search$/);
        await page.getByRole('searchbox', { name: 'Oldal vagy termék keresése', exact: true }).fill('ALPHA-001');
        await page.getByRole('button', { name: 'Keresés', exact: true }).click();
        await page.getByRole('main').getByRole('link', { name: /Alpha teszttermék/ }).click(); await expect(page).toHaveURL(/\/products\/1$/);
        await page.getByRole('button', { name: /^Nyitott feladatok,/ }).click();
        const menu = page.getByRole('menu', { name: 'Nyitott feladatok', exact: true }); await expect(menu).toBeVisible();
        const box = await menu.boundingBox(); expect(box.x).toBeGreaterThanOrEqual(0); expect(box.x + box.width).toBeLessThanOrEqual(320);
        await menu.getByRole('menuitem', { name: /Minősítésre váró visszáruk/ }).click(); await expect(page).toHaveURL(/quality-returns\?status=open/);
    } finally { await context.close(); }
});
