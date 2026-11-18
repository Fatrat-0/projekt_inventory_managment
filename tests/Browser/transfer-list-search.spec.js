import { expect, test } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { randomUUID } from 'node:crypto';
import { shipmentCommand as command } from './shipment-fixtures';

const workUrl = '/atmozgatasok/raktari-feladatok';
const receivingUrl = workUrl + '/atvetel';
const putawayUrl = workUrl + '/betarolas';

function fixture() {
    return JSON.parse(execFileSync('php', ['tests/Browser/prepare-shipment-receiving.php', '--open-picking'], { encoding: 'utf8' }));
}

async function login(page) {
    await page.goto('/login');
    await page.getByLabel('Email', { exact: true }).fill('warehouse-admin@example.test');
    await page.getByLabel('Password', { exact: true }).fill('browser-test-password');
    await page.getByRole('button', { name: 'Log in', exact: true }).click();
    await expect(page).toHaveURL(/dashboard/);
}

function health(page) {
    const failures = [];
    page.on('crash', () => failures.push('renderercrash'));
    page.on('pageerror', error => failures.push(error.message));
    return () => expect(failures).toEqual([]);
}

const suggestions = page => page.getByRole('listbox', { name: 'Kereső javaslatai', exact: true });
const suggestionRequest = request => {
    const url = new URL(request.url());
    return request.method() === 'GET' && url.searchParams.get('suggestions') === '1';
};

async function lookup(page, input, query) {
    const ready = page.waitForResponse(response => suggestionRequest(response.request())
        && new URL(response.url()).searchParams.get('search') === query);
    await input.fill(query);
    const response = await ready;
    expect(response.status(), await response.text()).toBe(200);
    const data = await response.json();
    expect(Array.isArray(data.items)).toBe(true);
    expect(data.items.length).toBeGreaterThan(0);
    expect(data.items.length).toBeLessThanOrEqual(8);
    await expect(suggestions(page)).toBeVisible();
    await expect(suggestions(page).getByRole('option')).toHaveCount(data.items.length);
    return data;
}

async function receipt(page, shipment) {
    const transit = JSON.parse(execFileSync('php', ['tests/Browser/read-shipment-receiving.php', String(shipment.id)], { encoding: 'utf8' })).transit;
    const response = await command(page, 'POST', '/shipment-plans/' + shipment.id + '/stops/' + shipment.pecs.id + '/receipts', {
        operation_key: randomUUID(), version: 1, confirmed: true,
        items: transit.filter(row => [shipment.products.screw.id, shipment.products.machine.id].includes(row.product_id))
            .map(row => ({ transit_item_id: row.id, quantity: row.remaining_quantity })),
    }, 201);
    return (await response.json()).receipt.id;
}

test('az öt lista gépelés közben javasol, választáskor megőrzi a friss szűrőket és a kétszintű rendezést', async ({ page }) => {
    test.setTimeout(90000);
    const shipment = fixture();
    const healthy = health(page);
    await login(page);
    const receiptId = await receipt(page, shipment);
    const source = String(shipment.source);
    const destination = String(shipment.pecs.warehouseId);
    const views = [
        {
            path: '/atmozgatasok', input: '#intent-search', form: '#intent-filters',
            query: shipment.products.screw.sku,
            initial: { warehouse_id: source, status: 'approved' },
            edits: [['priority', 'normal']],
            retained: { warehouse_id: source, status: 'approved', priority: 'normal' },
            sort: ['priority', 'requested_arrival_at'],
            rows: '[data-transfer-intent-list] [role="rowgroup"] > [role="row"]', expected: shipment.products.screw.sku,
        },
        {
            path: '/shipments', input: '#plan-search', form: '#shipment-filters', query: shipment.reference,
            initial: {}, edits: [['origin_warehouse_id', source], ['status', 'in_transit']],
            retained: { origin_warehouse_id: source, status: 'in_transit' },
            sort: ['reference', 'created_at'], rows: '[data-plan-id]', expected: shipment.number,
        },
        {
            path: workUrl, input: '#task-search', form: '#picking-filters', query: shipment.products.screw.sku,
            initial: { warehouse_id: source, status: 'all' }, edits: [['status', 'picked']],
            retained: { warehouse_id: source, status: 'picked' },
            sort: ['remaining_quantity', 'reference'],
            rows: '[data-picking-task-list] [role="rowgroup"] > [role="row"]', expected: shipment.products.screw.sku,
        },
        {
            path: receivingUrl, input: '#receiving-search', form: '#receiving-filters', query: shipment.products.screw.sku,
            initial: { status: 'all' }, edits: [['warehouse_id', destination]],
            retained: { warehouse_id: destination, status: 'all' },
            sort: ['shipment_id', 'remaining_quantity'], rows: '[data-testid="receiving-stop-row"]', expected: shipment.pecs.name,
        },
        {
            path: putawayUrl, input: '#putaway-task-search', form: '#putaway-filters', query: shipment.products.screw.sku,
            initial: { status: 'open', receipt_id: receiptId }, edits: [['warehouse_id', destination]],
            retained: { warehouse_id: destination, status: 'open', receipt_id: String(receiptId) },
            sort: ['pending_putaway_quantity', 'product_name'], rows: '[data-putaway-task-row]', expected: shipment.products.screw.sku,
        },
    ];
    for (const [index, view] of views.entries()) {
        await page.setViewportSize({ width: index % 2 === 0 ? 320 : 1440, height: 900 });
        const query = new URLSearchParams({ ...view.initial, per_page: '1', page: '2', sort_by: view.sort[0], sort_dir: 'desc', then_by: view.sort[1], then_dir: 'asc' });
        await page.goto(view.path + '?' + query);
        const form = page.locator(view.form);
        for (const [name, value] of view.edits) await form.locator('select[name="' + name + '"]').selectOption(value);
        const input = page.locator(view.input);
        await expect(input).toHaveAttribute('role', 'combobox');
        const data = await lookup(page, input, view.query);
        expect(new URL(page.url()).searchParams.get('page')).toBe('2');
        const option = suggestions(page).getByRole('option').first();
        await expect(option).toContainText(data.items[0].name);
        await option.scrollIntoViewIfNeeded();
        await option.click({ trial: true });
        const bounds = await suggestions(page).boundingBox();
        expect(bounds.x).toBeGreaterThanOrEqual(0);
        expect(bounds.x + bounds.width).toBeLessThanOrEqual(page.viewportSize().width);
        await option.click();
        await expect(page).toHaveURL(url => url.pathname === view.path && url.searchParams.get('search') === String(data.items[0].value));
        await page.waitForLoadState('load');
        const actual = new URL(page.url()).searchParams;
        for (const [key, value] of Object.entries({ ...view.retained, per_page: '1', sort_by: view.sort[0], sort_dir: 'desc', then_by: view.sort[1], then_dir: 'asc' })) {
            expect(actual.get(key), view.path + ' ' + key).toBe(value);
        }
        expect(actual.has('page')).toBe(false);
        await expect(page.locator(view.rows)).toHaveCount(1);
        await expect(page.locator(view.rows)).toContainText(view.expected);
        expect(await page.evaluate(() => Math.max(document.body.scrollWidth, document.documentElement.scrollWidth))).toBeLessThanOrEqual(page.viewportSize().width);
    }
    // A shipment can have multiple receiving stops with the same search value.
    await page.goto(receivingUrl + '?status=all');
    const duplicateValues = await lookup(page, page.locator('#receiving-search'), shipment.number);
    expect(duplicateValues.items).toHaveLength(2);
    expect(new Set(duplicateValues.items.map(item => item.value)).size).toBe(1);
    await expect(suggestions(page).getByRole('option')).toHaveCount(2);
    await expect(suggestions(page)).toContainText(shipment.pecs.name);
    await expect(suggestions(page)).toContainText(shipment.gyor.name);
    healthy();
});

test('a javaslatok billentyűzettel választhatók, Escape és Tab bezár, az Enter szabad szöveget is elfogad', async ({ page }) => {
    const shipment = fixture();
    const healthy = health(page);
    await login(page);
    await page.goto('/atmozgatasok?warehouse_id=' + shipment.source + '&status=approved');
    const input = page.locator('#intent-search');
    await lookup(page, input, shipment.products.screw.sku);
    await input.press('Escape');
    await expect(input).toHaveAttribute('aria-expanded', 'false');
    await expect(input).toHaveValue(shipment.products.screw.sku);
    const data = await lookup(page, input, shipment.products.screw.sku);
    await input.press('ArrowDown');
    await expect(input).toHaveAttribute('aria-activedescendant', /option-0$/);
    await input.press('Enter');
    await expect(page).toHaveURL(url => url.searchParams.get('search') === String(data.items[0].value));
    await page.waitForLoadState('load');
    await lookup(page, input, shipment.products.machine.sku);
    await input.press('Tab');
    await expect(input).toHaveAttribute('aria-expanded', 'false');
    await lookup(page, input, shipment.products.machine.sku);
    await input.press('Enter');
    await expect(page).toHaveURL(url => url.searchParams.get('search') === shipment.products.machine.sku);
    await page.waitForLoadState('load');
    await input.fill('nem-letezo-' + randomUUID());
    await expect(page.getByText('Nincs egyező javaslat. A megadott szövegre továbbra is kereshetsz.', { exact: true })).toBeVisible();
    const freeText = await input.inputValue();
    await input.press('Enter');
    await expect(page).toHaveURL(url => url.searchParams.get('search') === freeText);
    await page.waitForLoadState('load');
    await expect(page.getByRole('alert')).toHaveCount(0);
    await input.fill('');
    await expect(input).toHaveAttribute('aria-expanded', 'false');
    await input.press('Enter');
    await expect(page).toHaveURL(url => url.searchParams.get('search') === '');
    healthy();
});

test('a késői válasz nem írja felül az új keresést, a törlést vagy a bezárást', async ({ page }) => {
    // Also exercise generation checks when transport cancellation cannot stop an old response.
    await page.addInitScript(() => {
        const Original = window.AbortController;
        window.AbortController = class extends Original { abort() {} };
    });
    const healthy = health(page);
    await login(page);
    await page.goto('/atmozgatasok');
    const input = page.locator('#intent-search');
    let release;
    let arrived;
    let finished;
    let arrival = new Promise(resolve => { arrived = resolve; });
    let completion = new Promise(resolve => { finished = resolve; });
    await page.route('**/atmozgatasok?**', async route => {
        if (!suggestionRequest(route.request())) return route.continue();
        const query = new URL(route.request().url()).searchParams.get('search');
        if (query === 'HIBA') return route.fulfill({ status: 500, json: { message: 'Tesztelt hálózati hiba' } });
        if (query !== 'AKTUALIS') {
            const wait = new Promise(resolve => { release = resolve; });
            arrived();
            await wait;
        }
        try {
            await route.fulfill({ json: { items: [{ value: query, name: query === 'AKTUALIS' ? 'Aktuális javaslat' : 'Elavult javaslat', detail: query }], has_more: false } });
        } finally { if (query !== 'AKTUALIS') finished(); }
    });
    await input.fill('REGI');
    await arrival;
    await input.fill('AKTUALIS');
    await expect(suggestions(page).getByRole('option')).toHaveText(/Aktuális javaslat/);
    release();
    await completion;
    await page.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));
    await expect(suggestions(page).getByRole('option')).toHaveCount(1);
    await expect(suggestions(page).getByRole('option')).toHaveText(/Aktuális javaslat/);
    for (const [query, action] of [['TORLES', 'clear'], ['ESCAPE', 'escape'], ['TAB', 'tab'], ['SZURO', 'filter']]) {
        arrival = new Promise(resolve => { arrived = resolve; });
        completion = new Promise(resolve => { finished = resolve; });
        await input.fill(query);
        await arrival;
        if (action === 'clear') await input.fill('');
        else if (action === 'filter') await page.locator('#intent-filter-status').selectOption('approved');
        else await input.press(action === 'escape' ? 'Escape' : 'Tab');
        release();
        await completion;
        await page.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));
        await expect(input).toHaveAttribute('aria-expanded', 'false');
        await expect(suggestions(page)).toBeHidden();
    }
    await input.fill('HIBA');
    await expect(page.getByText('A javaslatok nem tölthetők be. A megadott szövegre továbbra is kereshetsz.', { exact: true })).toBeVisible();
    await input.press('Enter');
    await expect(page).toHaveURL(url => url.searchParams.get('search') === 'HIBA');
    await page.waitForLoadState('load');
    healthy();
});

test('a beviteli kompozíció után a kész szöveg kereshető, a mobil javaslat ténylegesen kattintható', async ({ page }) => {
    const shipment = fixture();
    const healthy = health(page);
    await login(page);
    await page.setViewportSize({ width: 320, height: 568 });
    await page.goto('/atmozgatasok?warehouse_id=' + shipment.source + '&status=approved');
    const input = page.locator('#intent-search');
    const urlBefore = page.url();
    await input.focus();
    await input.dispatchEvent('compositionstart');
    await input.evaluate((element, value) => {
        element.value = value;
        element.dispatchEvent(new InputEvent('input', { bubbles: true, isComposing: true, data: value }));
        element.dispatchEvent(new KeyboardEvent('keydown', { bubbles: true, key: 'Enter', code: 'Enter', isComposing: true, keyCode: 229 }));
    }, shipment.products.screw.sku);
    // A native implicit submit during IME must not start the outer form's loading state.
    await input.evaluate(element => element.form.requestSubmit());
    expect(page.url()).toBe(urlBefore);
    const filters = page.locator('#intent-filters');
    await expect(filters.locator('button[type="submit"]').filter({ hasText: 'Szűrés' })).toBeEnabled();
    await expect(filters.getByText('Betöltés…', { exact: true })).toBeHidden();
    const ready = page.waitForResponse(response => suggestionRequest(response.request())
        && new URL(response.url()).searchParams.get('search') === shipment.products.screw.sku);
    await input.dispatchEvent('compositionend', { data: shipment.products.screw.sku });
    const response = await ready;
    expect(response.status()).toBe(200);
    const option = suggestions(page).getByRole('option').first();
    await expect(option).toBeVisible();
    await option.scrollIntoViewIfNeeded();
    await option.click({ trial: true });
    const topmost = await option.evaluate(element => {
        const rect = element.getBoundingClientRect();
        return element.contains(document.elementFromPoint(rect.x + rect.width / 2, rect.y + rect.height / 2));
    });
    expect(topmost).toBe(true);
    const bounds = await suggestions(page).boundingBox();
    expect(bounds.x).toBeGreaterThanOrEqual(0);
    expect(bounds.x + bounds.width).toBeLessThanOrEqual(320);
    await option.click();
    await expect(page).not.toHaveURL(urlBefore);
    await page.waitForLoadState('load');
    await expect(page.locator('[data-transfer-intent-list] [role="rowgroup"] > [role="row"]')).toHaveCount(1);
    healthy();
});
