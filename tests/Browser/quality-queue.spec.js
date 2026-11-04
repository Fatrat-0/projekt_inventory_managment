import { test, expect } from '@playwright/test';
import { randomUUID } from 'node:crypto';

async function login(page, email = 'browser@example.test') {
    await page.goto('/login');
    await page.getByLabel('Email', { exact: true }).fill(email);
    await page.getByLabel('Password', { exact: true }).fill('browser-test-password');
    await page.getByRole('button', { name: 'Log in', exact: true }).click();
    await expect(page).toHaveURL(/dashboard/);
}
test.beforeEach(async ({ page }) => login(page));

const csrf = page => page.locator('meta[name="csrf-token"]').getAttribute('content');
const row = (page, id) => page.locator('[data-quality-return-id="'+id+'"]:visible');
const rows = page => page.locator('[data-quality-return-id]:visible');
const quality = page => page.getByRole('region', { name: 'Visszáru minősítése', exact: true });
async function post(page, url, data) {
    return page.request.post(url, { headers: { 'X-CSRF-TOKEN': await csrf(page) }, form: data, maxRedirects: 0 });
}

async function makeReturn(page, { marker, quantity = 8, product = 1, warehouse = 1, reason = marker, partner = '' }) {
    const common = { product_id: String(product), warehouse_id: String(warehouse), quantity: String(quantity) };
    // Supply this fixture's issue independently of the available balance left by previous cases.
    expect((await post(page, '/movements', { ...common, request_key: randomUUID(), type: 'in' })).status()).toBe(302);
    const reference = marker+'-OUT-'+randomUUID();
    expect((await post(page, '/movements', { ...common, request_key: randomUUID(), type: 'out', reference, partner_id: partner })).status()).toBe(302);
    const listing = await page.request.get('/movements?search='+encodeURIComponent(reference));
    expect(listing.ok()).toBe(true);
    const match = (await listing.text()).match(/href="[^"]*\/movements\/(\d+)(?:\?|")/);
    expect(match, 'The fixture issue must be present in movement history.').not.toBeNull();
    const response = await post(page, '/movements/'+match[1]+'/return', {
        request_key: randomUUID(), quantity: String(quantity), return_reason: reason, reference: marker+'-RETURN',
    });
    expect(response.status()).toBe(302);
    const path = new URL(response.headers().location).pathname;
    return { id: path.split('/').pop(), path, marker };
}
async function inspect(page, returned, action, quantity) {
    expect((await post(page, returned.path+'/quality', {
        request_key: randomUUID(), action, quantity: String(quantity), reason: 'Munkalista teszt: ellenőrizve',
    })).status()).toBe(302);
}
async function openQueue(page, params = {}) {
    await page.goto('/quality-returns?'+new URLSearchParams(params));
    await expect(page.getByRole('heading', { name: 'Minősítési munkalista', exact: true })).toBeVisible();
}
async function expectAmount(item, name, amount) {
    await expect(item.locator('[data-quality-quantity="'+name+'"]')).toHaveText(new RegExp('^'+amount+'(?:\\s*db)?$'));
}

test('queue displays remaining units and preserves its context through inspection and closure', async ({ page }) => {
    const marker = 'QUEUE-COUNTS-'+randomUUID();
    const returned = await makeReturn(page, { marker });
    await inspect(page, returned, 'release', 5);
    await openQueue(page, { search: marker, status: 'open', warehouse_id: '1', sort_by: 'remaining_quantity', sort_dir: 'desc', per_page: '10' });
    await expect(rows(page)).toHaveCount(1);
    const item = row(page, returned.id);
    await expect(item).toContainText('Részben minősítve');
    await expectAmount(item, 'original', 8);
    await expectAmount(item, 'released', 5);
    await expectAmount(item, 'scrapped', 0);
    await expectAmount(item, 'remaining', 3);
    await page.screenshot({ path: 'tests/Browser/.runtime/quality-queue-desktop.png', fullPage: true });
    await item.getByRole('link', { name: 'Minősítés', exact: true }).click();
    await expect(page).toHaveURL(url => url.hash === '#return-quality');
    await expect(quality(page)).toBeFocused();
    await expect(page.getByRole('link', { name: '← Minősítési munkalista', exact: true })).toBeVisible();
    await quality(page).getByLabel('Művelet', { exact: true }).selectOption('scrap');
    await quality(page).getByLabel('Mennyiség', { exact: true }).fill('3');
    await quality(page).getByLabel('Minősítés indoka', { exact: true }).fill('A maradék három darab sérült');
    await quality(page).getByRole('button', { name: 'Minősítés rögzítése', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Selejtezés megerősítése', exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Selejtezés véglegesítése', exact: true }).click();
    await expect(quality(page)).toContainText('A teljes visszáru minősítve.');
    await page.getByRole('link', { name: '← Minősítési munkalista', exact: true }).click();
    await expect(page).toHaveURL(url => url.pathname === '/quality-returns' && url.searchParams.get('search') === marker
        && url.searchParams.get('warehouse_id') === '1' && url.searchParams.get('status') === 'open'
        && url.searchParams.get('sort_by') === 'remaining_quantity' && url.searchParams.get('per_page') === '10');
    await expect(rows(page)).toHaveCount(0);
    await page.getByLabel('Állapot', { exact: true }).selectOption('closed');
    await expect(row(page, returned.id)).toContainText('Lezárt');
    await expectAmount(row(page, returned.id), 'remaining', 0);
    await expectAmount(row(page, returned.id), 'released', 5);
    await expectAmount(row(page, returned.id), 'scrapped', 3);
    await expect(row(page, returned.id).getByRole('link', { name: 'Megtekintés', exact: true })).toBeVisible();
});

test('product suggestions replace free text and typing clears the exact product before automatic warehouse filtering', async ({ page }) => {
    const marker = 'QUEUE-SEARCH-'+randomUUID();
    const alpha = await makeReturn(page, { marker: marker+'-ALPHA', quantity: 2 });
    const zeta = await makeReturn(page, { marker: marker+'-ZETA', quantity: 2, product: 2, warehouse: 2 });
    await openQueue(page, { search: marker, status: 'all' });
    await expect(rows(page)).toHaveCount(2);
    const search = page.getByRole('combobox', { name: 'Minősítések keresése', exact: true });
    await search.fill('Alpha teszttermék');
    await page.getByRole('option', { name: /Alpha teszttermék/ }).click();
    await expect(page).toHaveURL(url => url.searchParams.get('product_id') === '1' && !url.searchParams.has('search'));
    await expect(page.locator('input[name="product_id"]')).toHaveValue('1');
    await search.fill(marker);
    await expect(page.locator('input[name="product_id"]')).toHaveValue('');
    await search.press('Enter');
    await expect(rows(page)).toHaveCount(2);
    await expect(row(page, alpha.id)).toBeVisible();
    await page.getByLabel('Raktár', { exact: true }).selectOption('2');
    await expect(page).toHaveURL(url => url.searchParams.get('warehouse_id') === '2' && url.searchParams.get('search') === marker);
    await expect(rows(page)).toHaveCount(1);
    await expect(row(page, zeta.id)).toBeVisible();
    await page.getByRole('region', { name: 'Minősítési összesítők', exact: true }).getByRole('link', { name: /^Várakozik/ }).click();
    await expect(page).toHaveURL(url => url.searchParams.get('status') === 'waiting'
        && url.searchParams.get('warehouse_id') === '2' && url.searchParams.get('search') === marker);
    await expect(row(page, zeta.id)).toBeVisible();
});

test('oldest-first order is stable and column sorting preserves unsent search edits and page size', async ({ page }) => {
    const marker = 'QUEUE-SORT-'+randomUUID();
    const older = await makeReturn(page, { marker: marker+'-OLD', quantity: 8 });
    const newer = await makeReturn(page, { marker: marker+'-NEW', quantity: 2 });
    await openQueue(page, { search: marker, status: 'all', per_page: '10' });
    await expect(rows(page).first()).toHaveAttribute('data-quality-return-id', older.id);
    await expect(rows(page).last()).toHaveAttribute('data-quality-return-id', newer.id);
    const header = page.getByRole('columnheader').filter({ has: page.locator('a[href*="sort_by=remaining_quantity"]') });
    await header.getByRole('link').click();
    await expect(page).toHaveURL(url => url.searchParams.get('sort_by') === 'remaining_quantity' && url.searchParams.get('sort_dir') === 'asc');
    await expect(rows(page).first()).toHaveAttribute('data-quality-return-id', newer.id);
    await header.getByRole('link').click();
    await expect(page).toHaveURL(url => url.searchParams.get('sort_dir') === 'desc' && url.searchParams.get('per_page') === '10');
    await expect(rows(page).first()).toHaveAttribute('data-quality-return-id', older.id);
    await page.getByRole('combobox', { name: 'Minősítések keresése', exact: true }).fill(marker+'-OLD');
    await header.getByRole('link').click();
    await expect(page).toHaveURL(url => url.searchParams.get('search') === marker+'-OLD' && url.searchParams.get('per_page') === '10');
    await expect(rows(page)).toHaveCount(1);
    await expect(row(page, older.id)).toBeVisible();
});

test('an out-of-range page redirects to the last page and changing page size resets pagination', async ({ page }) => {
    const marker = 'QUEUE-PAGES-'+randomUUID();
    const returned = [];
    for (let index = 0; index < 11; index++) returned.push(await makeReturn(page, { marker: marker+'-'+index, quantity: 1 }));
    await openQueue(page, { search: marker, status: 'all', per_page: '10', page: '999' });
    await expect(page).toHaveURL(url => url.searchParams.get('page') === '2' && url.searchParams.get('search') === marker);
    await expect(rows(page)).toHaveCount(1);
    await expect(row(page, returned.at(-1).id)).toBeVisible();
    await page.getByLabel('Minősítések oldalanként', { exact: true }).selectOption('25');
    await expect(page).toHaveURL(url => url.searchParams.get('per_page') === '25' && !url.searchParams.has('page')
        && url.searchParams.get('search') === marker && url.searchParams.get('status') === 'all');
    await expect(rows(page)).toHaveCount(11);
});

test('dashboard and warehouse quarantine links open pending work scoped to the relevant stock', async ({ page }) => {
    const marker = 'QUEUE-LINKS-'+randomUUID();
    const returned = await makeReturn(page, { marker, quantity: 2 });
    await page.goto('/dashboard');
    await page.getByRole('region', { name: 'Minősítésre váró visszáruk', exact: true }).getByRole('link', { name: 'Minősítési munkalista', exact: true }).click();
    await expect(page).toHaveURL(url => url.pathname === '/quality-returns');
    await page.getByRole('combobox', { name: 'Minősítések keresése', exact: true }).fill(marker);
    await page.getByRole('combobox', { name: 'Minősítések keresése', exact: true }).press('Enter');
    await expect(row(page, returned.id)).toBeVisible();
    expect((await post(page, '/logout', {})).status()).toBe(302);
    await login(page, 'warehouse-admin@example.test');
    await page.goto('/warehouses/1');
    const link = page.locator('a[href*="/quality-returns"][href*="product_id=1"][href*="warehouse_id=1"]:visible').first();
    await expect(link).toContainText('Elkülönítve:');
    await link.click();
    await expect(page).toHaveURL(url => url.pathname === '/quality-returns' && url.searchParams.get('product_id') === '1'
        && url.searchParams.get('warehouse_id') === '1' && url.searchParams.get('status') === 'open');
    await page.getByRole('combobox', { name: 'Minősítések keresése', exact: true }).fill(marker);
    await page.getByRole('combobox', { name: 'Minősítések keresése', exact: true }).press('Enter');
    await expect(row(page, returned.id)).toBeVisible();
});

test('mobile queue works without JavaScript and preserves filters through its detail back link', async ({ page, browser }) => {
    const marker = 'QUEUE-MOBILE-'+randomUUID();
    const reason = marker+' <script>window.qualityQueueInjected=1</script> '+ 'W'.repeat(340);
    const returned = await makeReturn(page, { marker, quantity: 2, reason });
    const context = await browser.newContext({ javaScriptEnabled: false, storageState: await page.context().storageState(),
        baseURL: new URL(page.url()).origin, viewport: { width: 320, height: 800 } });
    try {
        const plain = await context.newPage();
        await openQueue(plain, { search: marker, status: 'all', warehouse_id: '1' });
        await plain.getByLabel('Állapot', { exact: true }).selectOption('waiting');
        await plain.getByRole('button', { name: 'Szűrés alkalmazása', exact: true }).click();
        await expect(plain).toHaveURL(url => url.searchParams.get('status') === 'waiting' && url.searchParams.get('search') === marker);
        await expect(rows(plain)).toHaveCount(1);
        expect(await plain.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
        await row(plain, returned.id).getByRole('link', { name: 'Minősítés', exact: true }).click();
        await expect(plain.getByRole('region', { name: 'Visszáru kapcsolata', exact: true })).toContainText(reason);
        await expect(plain.locator('script').filter({ hasText: 'qualityQueueInjected' })).toHaveCount(0);
        await plain.getByRole('link', { name: '← Minősítési munkalista', exact: true }).click();
        await expect(plain).toHaveURL(url => url.pathname === '/quality-returns' && url.searchParams.get('search') === marker
            && url.searchParams.get('status') === 'waiting' && url.searchParams.get('warehouse_id') === '1');
        expect(await plain.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
        await plain.screenshot({ path: 'tests/Browser/.runtime/quality-queue-mobile.png', fullPage: true });
    } finally { await context.close(); }
});

test('restoring a cached queue reloads current quantities while preserving filters', async ({ page }) => {
    const marker = 'QUEUE-CACHE-'+randomUUID();
    const returned = await makeReturn(page, { marker, quantity: 3 });
    await openQueue(page, { search: marker, warehouse_id: '1', per_page: '10' });
    await expectAmount(row(page, returned.id), 'remaining', 3);
    await inspect(page, returned, 'release', 3);
    const refreshed = page.waitForResponse(response => response.request().isNavigationRequest() && new URL(response.url()).pathname === '/quality-returns');
    await page.evaluate(() => { const event = new Event('pageshow'); Object.defineProperty(event, 'persisted', { value: true }); window.dispatchEvent(event); });
    await refreshed;
    await expect(rows(page)).toHaveCount(0);
    await expect(page).toHaveURL(url => url.searchParams.get('search') === marker && url.searchParams.get('warehouse_id') === '1' && url.searchParams.get('per_page') === '10');
});

test('maximum length names, partner and large quantities fit phone, tablet and desktop widths', async ({ page }) => {
    const marker = 'QUEUE-WIDE-'+randomUUID();
    const sku = ('EDGE-'+randomUUID()).padEnd(50, 'X');
    expect((await post(page, '/products', { product_name: 'W'.repeat(100), sku, price: '100', category_id: '1' })).status()).toBe(302);
    const productHtml = await (await page.request.get('/products?search='+sku)).text();
    const product = productHtml.match(/\/products\/(\d+)\/edit/)[1];
    const email = randomUUID()+'@example.test';
    expect((await post(page, '/partners', { name: 'P'.repeat(100), type: 'customer', email })).status()).toBe(302);
    const partnerHtml = await (await page.request.get('/partners?search='+email)).text();
    const partner = partnerHtml.match(/data-partner-id="(\d+)"/)[1];
    const returned = await makeReturn(page, { marker, product, partner, quantity: 2147483647 });
    await openQueue(page, { search: marker, partner_id: partner });
    for (const width of [320, 375, 767, 768, 1024, 1440]) {
        await page.setViewportSize({ width, height: 900 });
        await expect(row(page, returned.id)).toBeVisible();
        await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
        const overflow = await page.evaluate(() => ({ width: innerWidth, scroll: document.documentElement.scrollWidth, tableParent: [...document.querySelectorAll('table')].map(t => ({x: getComputedStyle(t.parentElement).overflowX, y: getComputedStyle(t.parentElement).overflowY, w: t.parentElement.clientWidth, sw: t.parentElement.scrollWidth})),
            elements: [...document.querySelectorAll('nav, main, form, select, table, [data-quality-return-id], a')].filter(el => { const r = el.getBoundingClientRect(); return r.width > 0 && (r.right > innerWidth + 1 || r.left < -1); }).slice(0, 10).map(el => ({ tag: el.tagName, cls: el.className, width: el.getBoundingClientRect().width })) }));
        if (overflow.scroll > width) await page.screenshot({ path: 'tests/Browser/.runtime/quality-queue-overflow.png', fullPage: true });
        expect(overflow.scroll <= width, JSON.stringify(overflow)).toBe(true);
        await expect(row(page, returned.id).locator('[data-quality-quantity="remaining"]')).toHaveText('2 147 483 647 db');
    }
    await page.setViewportSize({ width: 320, height: 900 });
    await page.screenshot({ path: 'tests/Browser/.runtime/quality-queue-long-mobile.png', fullPage: true });
});

