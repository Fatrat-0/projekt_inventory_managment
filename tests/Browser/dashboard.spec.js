import { test, expect } from '@playwright/test';
import { randomUUID } from 'node:crypto';

async function login(page, email = 'browser@example.test') {
    await page.goto('/login');
    await page.getByLabel('Email', { exact: true }).fill(email);
    await page.getByLabel('Password', { exact: true }).fill('browser-test-password');
    await page.getByRole('button', { name: 'Log in', exact: true }).click();
    await expect(page).toHaveURL(/dashboard/);
}
const csrf = page => page.locator('meta[name="csrf-token"]').getAttribute('content');
async function post(page, url, data) {
    return page.request.post(url, { headers: { 'X-CSRF-TOKEN': await csrf(page) }, data, maxRedirects: 0 });
}
async function product(page, minimum = 10, productName = null) {
    const sku = randomUUID()+'W'.repeat(14);
    const name = productName ?? 'DASH-'+randomUUID()+'W'.repeat(59);
    expect(name.length).toBeLessThanOrEqual(100);
    expect((await post(page, '/products', { product_name: name, sku, price: 100, category_id: 1, min_stock_override: minimum })).status()).toBe(302);
    const listing = await page.request.get('/products?search='+sku);
    const match = (await listing.text()).match(/href="[^"]*\/products\/(\d+)\/edit/);
    expect(match).not.toBeNull();
    return { id: Number(match[1]), name, sku };
}
async function movement(page, id, type, quantity) {
    const reference = 'DASH-'+randomUUID();
    const response = await post(page, '/movements', { request_key: randomUUID(), product_id: id, warehouse_id: 1, type, quantity, reference });
    expect(response.status()).toBe(302);
    const listing = await page.request.get('/movements?search='+reference);
    const match = (await listing.text()).match(/href="[^"]*\/movements\/(\d+)(?:\?|\")/);
    expect(match).not.toBeNull();
    return '/movements/'+match[1];
}

async function expectToggleBelowList(panel) {
    const scroller = panel.locator('.dashboard-list-scroll');
    const toggle = panel.locator('summary');
    const listBox = await scroller.boundingBox();
    const before = await toggle.boundingBox();
    expect(before.y).toBeGreaterThanOrEqual(listBox.y + listBox.height - 1);
    await scroller.evaluate(element => { element.scrollTop = element.scrollHeight; });
    const after = await toggle.boundingBox();
    expect(Math.abs(after.y - before.y)).toBeLessThanOrEqual(1);
}

const panelDestinations = [
    { id: 'product-alerts-title', path: '/products', filters: { stock_status: 'low' } },
    { id: 'category-alerts-title', path: '/categories', filters: {} },
    { id: 'pending-title', path: '/transfers', filters: { status: 'pending', sort_by: 'created_at', sort_dir: 'asc' } },
    { id: 'recent-title', path: '/movements', filters: {} },
];

test('panel navigation opens the matching full lists on desktop and the whole header on mobile', async ({ page }) => {
    await login(page, 'warehouse-admin@example.test');
    for (const width of [320, 375, 767, 768, 1024, 1440]) {
        await page.setViewportSize({ width, height: 900 });
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
        for (const target of panelDestinations) {
            const header = page.locator(`[data-panel-header="${target.id}"]`);
            await expect(header.locator('[data-panel-title-link]')).toBeVisible();
            if (width < 768) await expect(header.locator('[data-panel-page-link]')).toBeHidden();
            else await expect(header.locator('[data-panel-page-link]')).toBeVisible();
        }
    }
    for (const width of [1440, 375]) {
        await page.setViewportSize({ width, height: 900 });
        for (const target of panelDestinations) {
            await page.goto('/dashboard');
            const header = page.locator(`[data-panel-header="${target.id}"]`);
            const response = page.waitForResponse(response => response.request().isNavigationRequest() && new URL(response.url()).pathname === target.path);
            if (width >= 768) {
                await header.locator('[data-panel-page-link]').click();
            } else {
                await header.scrollIntoViewIfNeeded();
                const box = await header.boundingBox();
                // The blank bottom corner belongs to the stretched title link as well.
                await page.mouse.click(box.x + box.width - 10, box.y + box.height - 10);
            }
            expect((await response).status()).toBe(200);
            await expect(page).toHaveURL(url => url.pathname === target.path && Object.entries(target.filters).every(([key, value]) => url.searchParams.get(key) === value));
        }
    }
    await page.goto('/dashboard');
    await page.locator('[data-panel-header="product-alerts-title"]').screenshot({ path: 'tests/Browser/.runtime/dashboard-mobile-header.png' });
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.locator('[data-panel-header="pending-title"]').screenshot({ path: 'tests/Browser/.runtime/dashboard-desktop-header.png' });
});

test('worker panel headers remain usable without JavaScript and category navigation respects access', async ({ browser }) => {
    const context = await browser.newContext({ javaScriptEnabled: false, viewport: { width: 320, height: 900 } });
    const page = await context.newPage();
    try {
        await login(page);
        for (const target of panelDestinations) {
            await page.goto('/dashboard');
            const path = target.id === 'category-alerts-title' ? '/products' : target.path;
            const link = page.locator(`[data-panel-header="${target.id}"] [data-panel-title-link]`);
            if (target.id === 'category-alerts-title') await expect(link).toHaveAttribute('title', 'Terméklista');
            await link.focus();
            const response = page.waitForResponse(response => response.request().isNavigationRequest() && new URL(response.url()).pathname === path);
            await page.keyboard.press('Enter');
            expect((await response).status()).toBe(200);
            await expect(page).toHaveURL(url => url.pathname === path);
        }
    } finally { await context.close(); }
});

test('period changes preserve live cards and the daily details and drill-down match the selected calendar period', async ({ page }) => {
    await login(page);
    const count = await page.locator('[data-dashboard-card="transfers"]').textContent();
    await expect(page.locator('[data-activity-chart] > div')).toHaveCount(7);
    await page.getByLabel('Időszak', { exact: true }).selectOption('30');
    await expect(page).toHaveURL(url => url.searchParams.get('days') === '30');
    await expect(page.locator('[data-activity-chart] > div')).toHaveCount(30);
    await expect(page.locator('[data-dashboard-card="transfers"]')).toHaveText(count);
    await page.getByText('Napi részletek', { exact: true }).click();
    await expect(page.getByRole('table', { name: 'Készletmozgások napi bontása' }).locator('tbody tr')).toHaveCount(30);
    const href = await page.getByRole('link', { name: 'Időszak mozgásnaplója →', exact: true }).getAttribute('href');
    const url = new URL(href);
    expect(url.searchParams.has('date_from')).toBe(true);
    expect(url.searchParams.has('date_to')).toBe(true);
    await page.getByRole('link', { name: 'Időszak mozgásnaplója →', exact: true }).click();
    await expect(page).toHaveURL(url => url.pathname === '/movements' && url.searchParams.has('date_from') && url.searchParams.has('date_to'));
});

test('chart type and period persist together through changes and refresh, with matching daily values', async ({ page }) => {
    await login(page);
    const chartControls = page.getByRole('group', { name: 'Diagram típusa', exact: true });
    await expect(chartControls.getByRole('radio', { name: 'Oszlopdiagram', exact: true })).toBeChecked();
    expect((await chartControls.boundingBox()).width).toBeLessThanOrEqual(84);
    await expect(page.getByRole('button', { name: 'Mutat', exact: true })).toHaveCount(0);
    const total = await page.locator('[data-activity-total]').textContent();
    await page.getByRole('radio', { name: 'Vonaldiagram', exact: true }).check();
    await expect(page).toHaveURL(url => url.searchParams.get('chart') === 'line' && url.hash === '');
    await expect(page.locator('[data-activity-chart="line"]')).toBeVisible();
    await expect(page.locator('[data-activity-chart="bar"]')).toBeHidden();
    await expect(page.locator('[data-activity-chart="line"] polyline')).toHaveCount(2);
    await expect(page.locator('[data-activity-total]')).toHaveText(total);
    await page.getByLabel('Időszak', { exact: true }).selectOption('30');
    await expect(page).toHaveURL(url => url.searchParams.get('days') === '30' && url.searchParams.get('chart') === 'line');
    await expect(page.getByRole('radio', { name: 'Vonaldiagram', exact: true })).toBeChecked();
    await page.getByRole('link', { name: 'Frissítés', exact: true }).click();
    await expect(page.getByRole('radio', { name: 'Vonaldiagram', exact: true })).toBeChecked();
    await expect(page.getByLabel('Időszak', { exact: true })).toHaveValue('30');
    for (const width of [320, 375, 768, 1440]) {
        await page.setViewportSize({ width, height: 900 });
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
        const plot = await page.locator('[data-activity-chart="line"]').boundingBox();
        expect(plot.height).toBeGreaterThanOrEqual(159);
    }
    await page.locator('section[aria-labelledby="activity-title"]').screenshot({ path: 'tests/Browser/.runtime/dashboard-line.png' });
    await page.getByText('Napi részletek', { exact: true }).click();
    const values = await page.getByRole('table', { name: 'Készletmozgások napi bontása' }).locator('tbody').textContent();
    await page.getByRole('radio', { name: 'Oszlopdiagram', exact: true }).check();
    await expect(page).toHaveURL(url => url.searchParams.get('days') === '30' && url.searchParams.get('chart') === 'bar');
    await expect(page.locator('[data-activity-chart="bar"] > div')).toHaveCount(30);
    await expect(page.getByRole('table', { name: 'Készletmozgások napi bontása' })).toBeVisible();
    await expect(page.getByRole('table', { name: 'Készletmozgások napi bontása' }).locator('tbody')).toHaveText(values);
    await page.getByRole('radio', { name: 'Oszlopdiagram', exact: true }).focus();
    await page.keyboard.press('ArrowRight');
    await expect(page).toHaveURL(url => url.searchParams.get('chart') === 'line' && url.searchParams.get('days') === '30');
    await expect(page.getByRole('radio', { name: 'Vonaldiagram', exact: true })).toBeChecked();
});

test('chart and period changes keep viewport position and expanded lists without navigating the page', async ({ page }) => {
    await login(page);
    for (let index = 0; index < 4; index++) {
        expect((await post(page, '/products', { product_name: 'VIEWPORT-'+index, sku: randomUUID(), category_id: 1, price: 1, min_stock_override: 10000 })).status()).toBe(302);
    }
    await page.goto('/dashboard');
    const products = page.locator('[data-dashboard-list="product"]');
    await products.getByText('Több megjelenítése', { exact: true }).click();
    const heading = page.locator('#activity-title');
    await heading.evaluate(element => window.scrollTo(0, element.getBoundingClientRect().top + window.scrollY - 140));
    const before = await heading.boundingBox();
    const scroll = await page.evaluate(() => window.scrollY);
    let navigations = 0;
    page.on('request', request => { if (request.isNavigationRequest() && request.frame() === page.mainFrame()) navigations++; });
    await page.getByRole('radio', { name: 'Vonaldiagram', exact: true }).check();
    await expect(page).toHaveURL(url => url.searchParams.get('chart') === 'line' && !url.hash);
    expect(Math.abs((await heading.boundingBox()).y - before.y)).toBeLessThanOrEqual(2);
    expect(Math.abs(await page.evaluate(() => window.scrollY) - scroll)).toBeLessThanOrEqual(2);
    await expect(products.locator('details')).toHaveAttribute('open', '');
    await page.getByLabel('Időszak', { exact: true }).selectOption('30');
    await expect(page.locator('[data-activity-day]')).toHaveCount(30);
    await expect(page).toHaveURL(url => url.searchParams.get('days') === '30' && url.searchParams.get('chart') === 'line' && !url.hash);
    expect(Math.abs((await heading.boundingBox()).y - before.y)).toBeLessThanOrEqual(2);
    expect(Math.abs(await page.evaluate(() => window.scrollY) - scroll)).toBeLessThanOrEqual(2);
    await expect(products.locator('details')).toHaveAttribute('open', '');
    expect(navigations).toBe(0);
    const refresh = new URL(await page.getByRole('link', { name: 'Frissítés', exact: true }).getAttribute('href'));
    expect(refresh.searchParams.get('days')).toBe('30');
    expect(refresh.searchParams.get('chart')).toBe('line');
    await expect(page.locator('a[href="#category-alerts"]')).toHaveCount(1);
});

test('rapid period changes ignore an older response and keep the latest chart selection', async ({ page }) => {
    await login(page);
    let release;
    let ready;
    let done;
    const gate = new Promise(resolve => { release = resolve; });
    const intercepted = new Promise(resolve => { ready = resolve; });
    const completed = new Promise(resolve => { done = resolve; });
    await page.route('**/dashboard?**', async route => {
        if (new URL(route.request().url()).searchParams.get('days') !== '30') return route.continue();
        try {
            const response = await route.fetch();
            ready();
            await gate;
            try { await route.fulfill({ response }); } catch { /* The superseded fetch was aborted. */ }
        } finally { done(); }
    });
    try {
        await page.getByLabel('Időszak', { exact: true }).selectOption('30');
        await intercepted;
        await page.getByRole('radio', { name: 'Vonaldiagram', exact: true }).check();
        await page.getByLabel('Időszak', { exact: true }).selectOption('7');
        await expect(page.locator('form[aria-busy]')).toHaveAttribute('aria-busy', 'false');
        release();
        await completed;
        await expect(page).toHaveURL(url => url.searchParams.get('days') === '7' && url.searchParams.get('chart') === 'line');
        await expect(page.locator('[data-activity-day]')).toHaveCount(7);
        await expect(page.locator('[data-activity-chart="line"]')).toBeVisible();
    } finally { release(); await page.unroute('**/dashboard?**'); }
});

test('a failed period request preserves the chart and permits a retry', async ({ page }) => {
    await login(page);
    await page.route('**/dashboard?**', route => route.abort('failed'));
    await page.getByLabel('Időszak', { exact: true }).selectOption('30');
    await expect(page.getByRole('alert')).toContainText('A diagram nem frissült');
    await expect(page.getByLabel('Időszak', { exact: true })).toHaveValue('7');
    await expect(page.locator('[data-activity-day]')).toHaveCount(7);
    await page.unroute('**/dashboard?**');
    await page.getByLabel('Időszak', { exact: true }).selectOption('30');
    await expect(page.locator('[data-activity-day]')).toHaveCount(30);
    await expect(page.getByRole('alert')).toBeHidden();
});

for (const chart of ['bar', 'line']) {
    for (const days of [7, 30]) {
        test(`daily chart ${chart}/${days}: hover and keyboard expose values and a day opens its exact movement history`, async ({ page }) => {
            await login(page);
            await movement(page, 1, 'in', 7);
            await movement(page, 1, 'out', 3);
            const source = `/dashboard?days=${days}&chart=${chart}`;
            await page.goto(source);
            const targets = page.locator('[data-activity-day]');
            await expect(targets).toHaveCount(days);
            const zeroDate = await targets.first().getAttribute('data-activity-day');
            const currentDate = await targets.last().getAttribute('data-activity-day');
            await page.getByText('Napi részletek', { exact: true }).click();
            const row = page.getByRole('table', { name: 'Készletmozgások napi bontása' }).locator('tbody tr').last();
            const inText = (await row.locator('td').nth(0).textContent()).replace(/\s+/g, ' ').trim();
            const outText = (await row.locator('td').nth(1).textContent()).replace(/\s+/g, ' ').trim();
            const tooltip = page.getByRole('tooltip');
            await targets.last().hover();
            await expect(tooltip).toBeVisible();
            await expect(tooltip).toContainText(currentDate.replaceAll('-', '.')+'.');
            await expect(tooltip).toContainText(inText);
            await expect(tooltip).toContainText(outText);
            await page.keyboard.press('Escape');
            await expect(tooltip).toBeHidden();
            await page.getByRole('heading', { name: 'Vezérlőpult', exact: true }).hover();
            await targets.last().hover();
            await expect(tooltip).toBeVisible();
            const root = page.locator('[data-activity-interactive]');
            const box = await root.boundingBox();
            const tip = await tooltip.boundingBox();
            expect(tip.x).toBeGreaterThanOrEqual(box.x - 1);
            expect(tip.x + tip.width).toBeLessThanOrEqual(box.x + box.width + 1);
            await tooltip.hover();
            await expect(tooltip).toBeVisible();
            await page.getByRole('heading', { name: 'Vezérlőpult', exact: true }).hover();
            await expect(tooltip).toBeHidden();
            await targets.first().hover();
            await expect(tooltip).toContainText('0 művelet · 0 db');
            await targets.first().focus();
            await page.keyboard.press('Escape');
            await expect(tooltip).toBeHidden();
            await page.keyboard.press('ArrowRight');
            await expect(targets.nth(1)).toBeFocused();
            await expect(tooltip).toBeVisible();
            await page.keyboard.press('End');
            await expect(targets.last()).toBeFocused();
            await expect(tooltip).toContainText(currentDate.replaceAll('-', '.')+'.');
            await root.screenshot({ path: `tests/Browser/.runtime/chart-hover-${chart}-${days}.png` });
            await page.keyboard.press('Enter');
            await expect(page).toHaveURL(url => url.pathname === '/movements' && url.searchParams.get('date_from') === currentDate && url.searchParams.get('date_to') === currentDate);
            await page.goto(source);
            await targets.first().click();
            await expect(page).toHaveURL(url => url.pathname === '/movements' && url.searchParams.get('date_from') === zeroDate && url.searchParams.get('date_to') === zeroDate);
        });
    }
}

test('daily chart links work on touch screens and without JavaScript', async ({ browser }) => {
    const context = await browser.newContext({ viewport: { width: 375, height: 800 }, hasTouch: true });
    try {
        const page = await context.newPage();
        await login(page);
        await page.goto('/dashboard?days=30&chart=line');
        const day = page.locator('[data-activity-day]').last();
        const date = await day.getAttribute('data-activity-day');
        await day.tap();
        await expect(page).toHaveURL(url => url.pathname === '/movements' && url.searchParams.get('date_from') === date && url.searchParams.get('date_to') === date);
        await page.goto('/dashboard?chart=bar');
        await page.locator('[data-activity-day]').first().hover();
        await expect(page.getByRole('tooltip')).toBeVisible();
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
    } finally { await context.close(); }
    const native = await browser.newContext({ javaScriptEnabled: false });
    try {
        const page = await native.newPage();
        await login(page);
        await page.goto('/dashboard?chart=line');
        const day = page.locator('[data-activity-day]').first();
        const date = await day.getAttribute('data-activity-day');
        await expect(day).toHaveAttribute('title', /Bejövő:.*Kimenő:/);
        await day.click();
        await expect(page).toHaveURL(url => url.pathname === '/movements' && url.searchParams.get('date_from') === date && url.searchParams.get('date_to') === date);
    } finally { await native.close(); }
});

test('shortages use available stock, return work opens the queue and long names fit every breakpoint', async ({ page }) => {
    await login(page);
    const item = await product(page);
    await movement(page, item.id, 'in', 8);
    const issued = await movement(page, item.id, 'out', 6);
    const returned = await post(page, issued+'/return', { request_key: randomUUID(), quantity: 6, return_reason: 'Dashboard vizsgálat' });
    expect(returned.status()).toBe(302);
    const transfer = await post(page, '/transfers', { request_key: randomUUID(), product_id: item.id, from_warehouse_id: 1, to_warehouse_id: 2, quantity: 1 });
    expect(transfer.status()).toBe(302);
    await page.goto('/dashboard');
    const alerts = page.locator('[data-dashboard-list="product"]');
    const alert = alerts.locator('[data-product-alert="'+item.id+'"]:visible');
    if (await alert.count() === 0) await alerts.getByText('Több megjelenítése', { exact: true }).click();
    await expect(alert).toBeVisible();
    await expect(alert).toContainText('Kiadható: 1 db');
    await expect(alert).toContainText('Fizikai: 7 db');
    await expect(alert).toContainText('Elkülönítve: 6 db');
    await expect(alert).toContainText('Hiány: 9 db');
    for (const width of [320, 375, 767, 768, 1024, 1440]) {
        await page.setViewportSize({ width, height: 900 });
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
    }
    await page.setViewportSize({ width: 1440, height: 1000 });
    await page.screenshot({ path: 'tests/Browser/.runtime/dashboard-desktop.png', fullPage: true });
    await page.setViewportSize({ width: 375, height: 900 });
    await page.screenshot({ path: 'tests/Browser/.runtime/dashboard-mobile.png', fullPage: true });
    for (const id of ['product-alerts-title', 'pending-title', 'recent-title']) {
        await page.locator(`section[aria-labelledby="${id}"]`).screenshot({ path: `tests/Browser/.runtime/dashboard-mobile-${id}.png` });
    }
    const href = await alert.getByRole('link', { name: item.name, exact: true }).getAttribute('href');
    expect(new URL(href).searchParams.get('stock_status')).toBe('low');
    expect(new URL(href).searchParams.get('search')).toBe(item.sku);
    await alert.getByRole('link', { name: 'Termék mozgásnaplója →', exact: true }).click();
    await expect(page).toHaveURL(url => url.pathname === '/movements' && url.searchParams.get('product_id') === String(item.id));
    await page.goto('/dashboard');
    await page.getByRole('region', { name: 'Minősítésre váró visszáruk', exact: true }).getByRole('link', { name: 'Minősítési munkalista', exact: true }).click();
    await expect(page).toHaveURL(url => url.pathname === '/quality-returns' && url.searchParams.get('status') === 'open');
});

test('zero availability includes quarantine and missing stock, waiting ages stay readable and tasks precede the chart', async ({ page }) => {
    test.setTimeout(60000);
    await login(page);
    const quarantined = await product(page, 1000000, 'Ipari szerszámkészlet raktári karbantartáshoz');
    await movement(page, quarantined.id, 'in', 6);
    const issued = await movement(page, quarantined.id, 'out', 6);
    expect((await post(page, issued+'/return', { request_key: randomUUID(), quantity: 6, return_reason: 'Várakozási idő ellenőrzése' })).status()).toBe(302);
    const missing = await product(page, 1000000, 'Akkumulátoros szerelőkészlet tartozékokkal');
    const available = await product(page, 1000000, 'Professzionális kéziszerszám raktári munkához');
    await movement(page, available.id, 'in', 2);
    expect((await post(page, '/transfers', { request_key: randomUUID(), product_id: available.id, from_warehouse_id: 1, to_warehouse_id: 2, quantity: 1 })).status()).toBe(302);
    await page.goto('/dashboard');
    for (const item of [quarantined, missing]) {
        const row = page.locator(`[data-product-alert="${item.id}"]:visible`);
        await expect(row.locator('[data-no-available-stock]')).toHaveText('Nincs kiadható készlet');
    }
    const availableRow = page.locator(`[data-product-alert="${available.id}"]:visible`);
    await expect(availableRow).toBeVisible();
    await expect(availableRow.locator('[data-no-available-stock]')).toHaveCount(0);
    const age = page.locator('[data-transfer-waiting]:visible').last();
    await expect(age).toContainText(/(?:perce|órája|napja) úton/);
    await expect(age.locator('..').locator('time')).toHaveAttribute('datetime', /T/);
    await expect(page.locator('[data-quality-waiting]')).toContainText('minősítésre vár');
    await expect(page.locator('[data-quality-waiting]').locator('..').locator('time')).toHaveAttribute('datetime', /T/);
    for (const width of [320, 375, 768, 1024, 1440]) {
        await page.setViewportSize({ width, height: 900 });
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
        const activity = await page.locator('section[aria-labelledby="activity-title"]').boundingBox();
        for (const id of ['pending-title', 'recent-title']) {
            const panel = page.locator(`section[aria-labelledby="${id}"]`);
            const box = await panel.boundingBox();
            expect(box.y + box.height).toBeLessThanOrEqual(activity.y);
            expect(await panel.evaluate(element => Boolean(element.compareDocumentPosition(document.querySelector('section[aria-labelledby="activity-title"]')) & Node.DOCUMENT_POSITION_FOLLOWING))).toBe(true);
        }
    }
    await page.setViewportSize({ width: 375, height: 900 });
    await page.locator('section[aria-labelledby="product-alerts-title"]').screenshot({ path: 'tests/Browser/.runtime/dashboard-refined-shortages-mobile.png' });
    await page.locator('section[aria-labelledby="pending-title"]').screenshot({ path: 'tests/Browser/.runtime/dashboard-refined-transfers-mobile.png' });
});

test('pending shipment items link to the shipment and receipt clears the live dashboard task', async ({ page }) => {
    await login(page);
    const item = await product(page, 0);
    await movement(page, item.id, 'in', 10);
    const reference = 'Dashboard-'+randomUUID();
    const created = await post(page, '/shipments', { reference, origin_warehouse_id: 1,
        stops: [{ warehouse_id: 2, items: [{ product_id: item.id, quantity: 5 }] }] });
    expect(created.status()).toBe(302);
    const shipment = new URL(created.headers().location).pathname;
    expect((await post(page, shipment+'/start', { version: 1 })).status()).toBe(302);
    await page.goto('/dashboard');
    const pending = page.locator('[data-pending-transfer]').filter({ hasText: item.name });
    await expect(pending).toHaveCount(1);
    await pending.getByRole('link').click();
    await expect(page).toHaveURL(url => url.pathname === shipment);
    await expect(page.getByRole('heading', { name: reference, exact: true })).toBeVisible();
    page.once('dialog', dialog => dialog.accept());
    await page.getByRole('button', { name: 'Megálló átvétele', exact: true }).click();
    await expect(page.getByText('Teljesítve', { exact: true })).toBeVisible();
    await page.goto('/dashboard');
    await expect(page.locator('[data-pending-transfer]').filter({ hasText: item.name })).toHaveCount(0);
});

test('invalid periods recover once and restoring a cached dashboard reloads current values', async ({ page }) => {
    await login(page);
    await page.goto('/dashboard?days[]=7');
    await expect(page).toHaveURL(url => url.pathname === '/dashboard' && !url.searchParams.has('days[]'));
    await expect(page.getByRole('alert')).toContainText('Az időszak nem érvényes');
    const loaded = page.waitForEvent('load');
    await page.evaluate(() => window.dispatchEvent(new PageTransitionEvent('pageshow', { persisted: true })));
    await loaded;
    await expect(page.getByRole('heading', { name: 'Vezérlőpult', exact: true })).toBeVisible();
});

test('period selection works without JavaScript and workers do not receive category administration links', async ({ browser }) => {
    const context = await browser.newContext({ javaScriptEnabled: false });
    const page = await context.newPage();
    try {
        await login(page);
        await page.getByLabel('Időszak', { exact: true }).selectOption('30');
        await page.getByRole('radio', { name: 'Vonaldiagram', exact: true }).check();
        await page.getByRole('button', { name: 'Mutat', exact: true }).click();
        await expect(page).toHaveURL(url => url.searchParams.get('days') === '30' && url.searchParams.get('chart') === 'line');
        await expect(page.locator('[data-activity-chart="line"] polyline')).toHaveCount(2);
        await expect(page.locator('a[href*="/categories/"][href*="/edit"]')).toHaveCount(0);
    } finally { await context.close(); }
});

test('shortage lists expand independently, scroll within the panel, and page without losing chart settings even without JavaScript', async ({ page, browser }) => {
    test.setTimeout(90000);
    await login(page, 'warehouse-admin@example.test');
    const existingProducts = Number((await page.locator('[data-dashboard-card="low-products"]').textContent()).replace(/\s/g, ''));
    const existingCategories = Number((await page.locator('#category-alerts-title').textContent()).match(/\(([\d\s]+)\)/)[1].replace(/\s/g, ''));
    const prefix = 'EXPAND-'+randomUUID();
    for (let index = 0; index < 28; index++) {
        const name = prefix+'-'+index;
        expect((await post(page, '/categories', { category_name: name, aggregate_min_stock: 1000 + index })).status()).toBe(302);
        const listing = await page.request.get('/categories?search='+encodeURIComponent(name));
        const match = (await listing.text()).match(/data-category-id="(\d+)"/);
        expect(match).not.toBeNull();
        expect((await post(page, '/products', { product_name: name+'W'.repeat(100 - name.length), sku: randomUUID(), category_id: Number(match[1]), price: 1, min_stock_override: 1000 + index })).status()).toBe(302);
    }
    await page.goto('/dashboard?days=30&chart=line');
    const products = page.locator('[data-dashboard-list="product"]');
    const categories = page.locator('[data-dashboard-list="category"]');
    await expect(products.locator('[data-product-alert]:visible')).toHaveCount(3);
    await expect(categories.locator('[data-category-alert]:visible')).toHaveCount(3);
    await products.locator('summary').focus();
    await page.keyboard.press('Enter');
    await expect(products.locator('[data-product-alert]:visible')).toHaveCount(25);
    await expect(categories.locator('[data-category-alert]:visible')).toHaveCount(3);
    await expectToggleBelowList(products);
    const scroller = products.locator('.dashboard-list-scroll');
    await expect.poll(() => scroller.evaluate(element => element.scrollHeight > element.clientHeight)).toBe(true);
    await scroller.focus();
    await page.keyboard.press('End');
    await expect.poll(() => scroller.evaluate(element => element.scrollTop)).toBeGreaterThan(0);
    await products.getByText('Kevesebb megjelenítése', { exact: true }).click();
    await expect(products.locator('[data-product-alert]:visible')).toHaveCount(3);
    await expect.poll(() => scroller.evaluate(element => element.scrollTop)).toBe(0);
    await categories.getByText('Több megjelenítése', { exact: true }).click();
    await expect(categories.locator('[data-category-alert]:visible')).toHaveCount(25);
    await expectToggleBelowList(categories);
    await products.getByText('Több megjelenítése', { exact: true }).click();
    for (const [width, height] of [[320, 900], [375, 500], [768, 900], [1440, 900]]) {
        await page.setViewportSize({ width, height });
        await expect.poll(() => scroller.evaluate(element => element.clientHeight <= window.innerHeight * 0.7 + 2)).toBe(true);
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
    }
    await page.locator('section[aria-labelledby="product-alerts-title"]').screenshot({ path: 'tests/Browser/.runtime/dashboard-expanded-alerts.png' });
    await products.getByRole('link', { name: 'Következő oldal →', exact: true }).click();
    await expect(page).toHaveURL(url => url.searchParams.get('product_alert_page') === '2' && url.searchParams.get('days') === '30' && url.searchParams.get('chart') === 'line' && url.hash === '#product-alerts-title');
    await expect(products.locator('[data-product-alert]:visible')).toHaveCount(Math.min(25, 28 + existingProducts - 25));
    await page.getByRole('link', { name: 'Frissítés', exact: true }).click();
    await expect(page).toHaveURL(url => url.searchParams.get('product_alert_page') === '2');
    const native = await browser.newContext({ javaScriptEnabled: false });
    try {
        const other = await native.newPage();
        await login(other, 'warehouse-admin@example.test');
        await other.goto('/dashboard?days=30&chart=line');
        const nativeCategories = other.locator('[data-dashboard-list="category"]');
        await expect(nativeCategories.locator('[data-category-alert]:visible')).toHaveCount(3);
        await nativeCategories.getByText('Több megjelenítése', { exact: true }).click();
        await expect(nativeCategories.locator('[data-category-alert]:visible')).toHaveCount(25);
        await expectToggleBelowList(nativeCategories);
        expect(await nativeCategories.locator('.dashboard-list-scroll').evaluate(element => element.scrollHeight > element.clientHeight)).toBe(true);
        await nativeCategories.getByRole('link', { name: 'Következő oldal →', exact: true }).click();
        await expect(other).toHaveURL(url => url.searchParams.get('category_alert_page') === '2' && url.searchParams.get('chart') === 'line');
        await expect(nativeCategories.locator('[data-category-alert]:visible')).toHaveCount(Math.min(25, 28 + existingCategories - 25));
    } finally { await native.close(); }
});

test('pending transfers and recent movements share the compact scrolling lists and preserve independent pages', async ({ page, browser }) => {
    test.setTimeout(90000);
    await login(page);
    const existingTransfers = Number((await page.locator('[data-dashboard-card="transfers"]').textContent()).replace(/\s/g, ''));
    const item = await product(page, 0);
    for (let index = 0; index < 28; index++) {
        expect((await post(page, '/movements', { request_key: randomUUID(), product_id: item.id, warehouse_id: 1, type: 'in', quantity: 1 })).status()).toBe(302);
        expect((await post(page, '/transfers', { request_key: randomUUID(), product_id: item.id, from_warehouse_id: 1, to_warehouse_id: 2, quantity: 1 })).status()).toBe(302);
    }
    await page.goto('/dashboard?days=30&chart=line&product_alert_page=1&category_alert_page=1');
    const transfers = page.locator('[data-dashboard-list="transfer"]');
    const movements = page.locator('[data-dashboard-list="movement"]');
    await expect(transfers.locator('[data-pending-transfer]:visible')).toHaveCount(3);
    await expect(movements.locator('[data-recent-movement]:visible')).toHaveCount(3);
    await transfers.getByText('Több megjelenítése', { exact: true }).click();
    await expect(transfers.locator('[data-pending-transfer]:visible')).toHaveCount(25);
    await expect(movements.locator('[data-recent-movement]:visible')).toHaveCount(3);
    await movements.locator('summary').focus();
    await page.keyboard.press('Enter');
    await expect(movements.locator('[data-recent-movement]:visible')).toHaveCount(25);
    for (const panel of [transfers, movements]) {
        await expect.poll(() => panel.locator('.dashboard-list-scroll').evaluate(element => element.scrollHeight > element.clientHeight)).toBe(true);
        await expectToggleBelowList(panel);
    }
    for (const width of [320, 375, 768, 1440]) {
        await page.setViewportSize({ width, height: 900 });
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
        await expect.poll(() => movements.locator('.dashboard-list-scroll').evaluate(element => element.clientHeight <= window.innerHeight * 0.7 + 2)).toBe(true);
    }
    await page.locator('section[aria-labelledby="recent-title"]').screenshot({ path: 'tests/Browser/.runtime/dashboard-expanded-movements.png' });
    await transfers.getByRole('link', { name: 'Következő oldal →', exact: true }).click();
    await expect(page).toHaveURL(url => url.searchParams.get('transfer_page') === '2' && url.searchParams.get('chart') === 'line' && url.hash === '#pending-title');
    await expect(transfers.locator('[data-pending-transfer]:visible')).toHaveCount(Math.min(25, 28 + existingTransfers - 25));
    await movements.getByRole('link', { name: 'Következő oldal →', exact: true }).click();
    await expect(page).toHaveURL(url => ['transfer_page', 'movement_page'].every(key => url.searchParams.get(key) === '2') && url.searchParams.get('product_alert_page') === '1' && url.searchParams.get('days') === '30');
    await expect(movements.locator('[data-recent-movement]:visible')).toHaveCount(25);
    await page.getByLabel('Időszak', { exact: true }).selectOption('7');
    await expect(page).toHaveURL(url => url.searchParams.get('days') === '7' && url.searchParams.get('movement_page') === '2' && url.searchParams.get('transfer_page') === '2');
    await movements.getByText('Kevesebb megjelenítése', { exact: true }).click();
    await expect(movements.locator('[data-recent-movement]:visible')).toHaveCount(3);
    const native = await browser.newContext({ javaScriptEnabled: false });
    try {
        const other = await native.newPage();
        await login(other);
        const nativeMovements = other.locator('[data-dashboard-list="movement"]');
        await expect(nativeMovements.locator('[data-recent-movement]:visible')).toHaveCount(3);
        await nativeMovements.getByText('Több megjelenítése', { exact: true }).click();
        await expect(nativeMovements.locator('[data-recent-movement]:visible')).toHaveCount(25);
        await expectToggleBelowList(nativeMovements);
        expect(await nativeMovements.locator('.dashboard-list-scroll').evaluate(element => element.scrollHeight > element.clientHeight)).toBe(true);
        await nativeMovements.locator('summary').focus();
        await other.keyboard.press('Enter');
        await expect(nativeMovements.locator('[data-recent-movement]:visible')).toHaveCount(3);
    } finally { await native.close(); }
});
