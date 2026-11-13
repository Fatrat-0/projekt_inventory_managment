import { expect, test } from '@playwright/test';
import { randomUUID } from 'node:crypto';
import { join } from 'node:path';

const listPath = '/atmozgatasok';
const createPath = listPath + '/uj-igeny';
const detailsPath = id => listPath + '/igenyek/' + id;
const adminEmail = 'warehouse-admin@example.test';
const requesterEmail = 'browser@example.test';
const receiverEmail = 'receiving-intents@example.test';
const source = 'Teszt raktár';
const destination = 'Északi tesztraktár';
const screenshotDirectory = process.env.INTENT_SCREENSHOT_DIR || 'tests/Browser/.runtime/transfer-intents';

async function login(page, email = adminEmail) {
    await page.goto('/login');
    await page.getByLabel('Email', { exact: true }).fill(email);
    await page.getByLabel('Password', { exact: true }).fill('browser-test-password');
    await page.getByRole('button', { name: 'Log in', exact: true }).click();
    await expect(page).toHaveURL(/dashboard/);
}

async function choose(page, id, query, name) {
    const field = page.locator(id);
    await field.fill(query);
    await page.getByRole('option', { name }).click();
}

async function fillRoute(page, {
    sku = 'ALPHA-001', productName = /Alpha teszttermék/, from = source, to = destination,
} = {}) {
    await choose(page, '#intent-product', sku, productName);
    await choose(page, '#intent-from-warehouse', from, from);
    await choose(page, '#intent-to-warehouse', to, to);
}

async function headers(page) {
    return { Accept: 'application/json', 'X-CSRF-TOKEN': await page.locator('meta[name="csrf-token"]').getAttribute('content') };
}

async function apiIntent(page, fields = {}) {
    const response = await page.request.post('/transfer-intents', {
        headers: await headers(page),
        data: { product_id: 1, from_warehouse_id: 1, to_warehouse_id: 2, quantity: 25, operation_key: randomUUID(), ...fields },
    });
    expect(response.status(), await response.text()).toBe(201);
    return (await response.json()).intent;
}

async function apiDecision(page, intent, action, reason) {
    const response = await page.request.patch('/transfer-intents/' + intent.id + '/' + action, {
        headers: await headers(page),
        data: { version: intent.version, operation_key: randomUUID(), reason },
    });
    expect(response.status(), await response.text()).toBe(200);
    return (await response.json()).intent;
}

async function packagedProduct(page, name = 'Dobozolt igénypróba') {
    const sku = 'INTENT-' + randomUUID().slice(0, 8);
    const response = await page.request.post('/products', {
        headers: await headers(page), maxRedirects: 0,
        data: { product_name: name, sku, category_id: 1, price: 1, packaging: { name: 'Doboz', pieces_per_package: 100 } },
    });
    expect(response.status(), await response.text()).toBe(302);
    const listing = await (await page.request.get('/products?search=' + sku)).text();
    const match = listing.match(/href="[^"]*\/products\/(\d+)\/edit/);
    expect(match).not.toBeNull();
    return { sku, productName: name + ' (' + sku + ')', id: Number(match[1]) };
}

async function newWarehouse(page, name) {
    const response = await page.request.post('/warehouses', {
        headers: await headers(page), data: { warehouse_name: name, location: 'Bemutató telephely' }, maxRedirects: 0,
    });
    expect(response.status(), await response.text()).toBe(302);
    await page.goto(response.headers().location);
    const href = await page.getByRole('link', { name, exact: true }).first().getAttribute('href');
    return Number(new URL(href, page.url()).pathname.split('/').at(-1));
}

function dialogAction(page, action, label) {
    return {
        open: page.getByRole('button', { name: action, exact: true }),
        dialog: page.getByRole('dialog'),
        reason: page.getByRole('dialog').getByLabel(label, { exact: true }),
        confirm: page.getByRole('dialog').getByRole('button', { name: 'Igény ' + action.toLowerCase() + 'a', exact: true }),
    };
}

test('warehouse members on either side can submit a request even above current stock', async ({ page, browser, baseURL }) => {
    await login(page, requesterEmail);
    const csrf = await headers(page);
    const before = await (await page.request.get('/movements/stock?product_id=1&warehouse_id=1', { headers: csrf })).json();
    await page.goto(createPath);
    await expect(page.getByRole('heading', { name: 'Új átmozgatási igény' })).toBeVisible();
    await fillRoute(page);
    await page.locator('#intent-quantity').fill('200');
    await page.getByRole('button', { name: 'Igény benyújtása' }).click();
    await expect(page).toHaveURL(/\/atmozgatasok\/igenyek\/\d+(?:\?submitted=1)?$/);
    await expect(page.getByRole('status')).toContainText('Az átmozgatási igényt benyújtottad.');
    await expect(page.locator('[data-intent-status]')).toHaveText('Jóváhagyásra vár');
    await expect(page.getByRole('heading', { name: 'Eseménytörténet' })).toBeVisible();
    await expect(page.getByText('Nincs megadva', { exact: true })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Jóváhagyás', exact: true })).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Visszavonás', exact: true })).toBeVisible();
    const after = await (await page.request.get('/movements/stock?product_id=1&warehouse_id=1', { headers: csrf })).json();
    expect(after).toEqual(before);

    const receiverContext = await browser.newContext({ baseURL });
    try {
        const receiver = await receiverContext.newPage();
        await login(receiver, receiverEmail);
        await receiver.goto(createPath);
        await fillRoute(receiver, { sku: 'ZETA-002', productName: /Zeta teszttermék/ });
        await receiver.locator('#intent-quantity').fill('10');
        await receiver.getByRole('button', { name: 'Igény benyújtása' }).click();
        await expect(receiver).toHaveURL(/\/atmozgatasok\/igenyek\/\d+(?:\?submitted=1)?$/);
        await expect(receiver.locator('[data-intent-status]')).toHaveText('Jóváhagyásra vár');
        await expect(receiver.getByRole('button', { name: 'Jóváhagyás', exact: true })).toHaveCount(0);
        const receiverDetail = new URL(receiver.url()).pathname;
        await receiver.goto(listPath);
        await expect(receiver.getByText('Zeta teszttermék', { exact: true })).toBeVisible();
        await expect(receiver.getByText('Alpha teszttermék', { exact: true })).toBeVisible();
        expect((await page.goto(receiverDetail)).status()).toBe(403);
        await page.goto(listPath);
        await expect(page.getByText('Alpha teszttermék', { exact: true })).toBeVisible();
        await expect(page.getByText('Zeta teszttermék', { exact: true })).toHaveCount(0);
    } finally {
        await receiverContext.close();
    }
});

test('boxed submission preserves pieces, disables duplicate clicks, and reuses the operation key after a lost response', async ({ page }) => {
    await login(page);
    const product = await packagedProduct(page);
    await page.goto(createPath);
    await fillRoute(page, product);
    const pieces = page.locator('#intent-quantity');
    const boxes = page.locator('#intent-box-count');
    const boxMode = page.getByRole('button', { name: 'Doboz', exact: true });
    await pieces.fill('150');
    await boxMode.click();
    await expect(pieces).toHaveValue('150');
    await expect(boxes).not.toBeVisible();
    await pieces.fill('200');
    await pieces.press('Tab');
    await expect(page.getByRole('button', { name: 'Darab', exact: true })).toBeFocused();
    await page.keyboard.press('Tab');
    await expect(boxMode).toBeFocused();
    await expect(boxMode).toHaveCSS('outline-style', 'solid');
    await page.keyboard.press('Enter');
    await expect(boxMode).toHaveAttribute('aria-pressed', 'true');
    await expect(boxes).toHaveValue('2');
    await expect(boxes).toBeFocused();
    await page.locator('#intent-note').fill('Idempotencia böngészőteszt');
    await page.locator('#intent-arrival').fill('2027-11-20T13:30');

    const sent = [];
    const results = [];
    let releaseResponse;
    const paused = new Promise(resolve => { releaseResponse = resolve; });
    await page.route('**/transfer-intents', async route => {
        if (route.request().method() !== 'POST') return route.continue();
        sent.push(route.request().postDataJSON());
        const response = await route.fetch();
        expect(response.status()).toBe(201);
        results.push(await response.json());
        if (sent.length === 1) {
            await paused;
            return route.abort('failed');
        }
        return route.fulfill({ response });
    });
    const submit = page.getByRole('button', { name: /^(Igény benyújtása|Benyújtás…)$/ });
    await submit.evaluate(button => { button.click(); button.click(); });
    try {
        await expect.poll(() => results.length).toBe(1);
        await expect(submit).toBeDisabled();
        expect(sent).toHaveLength(1);
    } finally {
        releaseResponse();
    }
    await expect(submit).toBeEnabled();
    await expect(page.locator('#intent-note')).toHaveValue('Idempotencia böngészőteszt');
    await submit.click();
    await expect(page).toHaveURL(/\/atmozgatasok\/igenyek\/\d+(?:\?submitted=1)?$/);
    expect(sent).toHaveLength(2);
    expect(Number(sent[0].quantity)).toBe(200);
    expect(Number(sent[0].product_id)).toBe(product.id);
    expect(sent[1]).toEqual(sent[0]);
    expect(results[1]).toEqual(results[0]);
    await expect(page.locator('[data-intent-status]')).toHaveText('Jóváhagyásra vár');
});

test('conflicting decision can be refreshed without retrying or losing the typed reason', async ({ page }) => {
    await login(page);
    const intent = await apiIntent(page);
    await page.goto(detailsPath(intent.id));
    const reject = dialogAction(page, 'Elutasítás', 'Elutasítás indoka');
    await reject.open.click();
    await expect(reject.dialog).toBeVisible();
    await expect(reject.reason).toBeFocused();
    await reject.reason.fill('A megszervezett szállítás más határidővel érkezik.');
    const requests = [];
    page.on('request', request => {
        if (request.method() === 'PATCH' && request.url().endsWith('/reject')) requests.push(request);
    });
    await apiDecision(page, intent, 'approve', 'Párhuzamos jóváhagyás');
    await reject.confirm.click();
    await expect(reject.dialog).toContainText(/közben megváltozott/);
    await expect(reject.reason).toHaveValue('A megszervezett szállítás más határidővel érkezik.');
    await reject.dialog.getByRole('button', { name: 'Aktuális adatok frissítése' }).click();
    await expect(page.locator('[data-intent-status]')).toHaveText('Jóváhagyva');
    await expect(reject.reason).toHaveValue('A megszervezett szállítás más határidővel érkezik.');
    await expect(reject.confirm).toBeDisabled();
    expect(requests).toHaveLength(1);
    await reject.dialog.getByRole('button', { name: 'Bezárás', exact: true }).click();
    await expect(reject.open).toHaveCount(0);
    await expect(page.getByText('Párhuzamos jóváhagyás', { exact: true })).toBeVisible();
});

test('admin can confirm approval, rejection, and cancellation and see their history', async ({ page }) => {
    await login(page);
    const approved = await apiIntent(page, { note: 'Jóváhagyás és visszavonás' });
    await page.goto(detailsPath(approved.id));
    const approve = dialogAction(page, 'Jóváhagyás', 'Jóváhagyás indoka (opcionális)');
    await approve.open.click();
    await expect(approve.dialog).toBeVisible();
    await expect(page.locator('[data-intent-status]')).toHaveText('Jóváhagyásra vár');
    await approve.confirm.click();
    await expect(page.locator('[data-intent-status]')).toHaveText('Jóváhagyva');
    await expect(approve.dialog).not.toBeVisible();

    const cancel = dialogAction(page, 'Visszavonás', 'Visszavonás indoka');
    await cancel.open.click();
    await cancel.reason.fill('A célraktár másik terméket kért.');
    await cancel.confirm.click();
    await expect(page.locator('[data-intent-status]')).toHaveText('Visszavonva');
    await expect(page.getByText('A célraktár másik terméket kért.', { exact: true })).toBeVisible();

    const rejected = await apiIntent(page);
    await page.goto(detailsPath(rejected.id));
    const reject = dialogAction(page, 'Elutasítás', 'Elutasítás indoka');
    await reject.open.click();
    await expect(reject.reason).toHaveJSProperty('required', true);
    await reject.reason.fill('A célraktár időpontja módosult.');
    await reject.confirm.click();
    await expect(page.locator('[data-intent-status]')).toHaveText('Elutasítva');
    await expect(page.getByText('A célraktár időpontja módosult.', { exact: true })).toBeVisible();
});

test('the list, form and workflow states fit desktop through 320 px', async ({ page }) => {
    test.setTimeout(120000);
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await login(page);
    const longProduct = 'Rozsdamentes ipari kötőelem biztonsági rögzítéssel és megerősített csomagolással – bemutató';
    const longSource = 'Központi áruelosztó és készlet-előkészítő bemutató raktár';
    const longDestination = 'Dél-dunántúli szerelőipari és logisztikai fogadó bemutató raktár';
    const product = await packagedProduct(page, longProduct);
    const from = await newWarehouse(page, longSource);
    const to = await newWarehouse(page, longDestination);
    const pending = await apiIntent(page, {
        product_id: product.id, from_warehouse_id: from, to_warehouse_id: to,
        priority: 'urgent', requested_arrival_at: '2027-11-20T13:30:00+01:00',
        reason: 'A szerelési ütemtervhez kapcsolódó utánpótlás.',
    });
    const approved = await apiDecision(page, await apiIntent(page, {
        product_id: product.id, from_warehouse_id: from, to_warehouse_id: to, priority: 'critical',
    }), 'approve', 'Az ütemezés ellenőrizve.');

    const screenshot = async (label, width) => {
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), label + ' ' + width + 'px').toBe(true);
        await page.screenshot({ path: join(screenshotDirectory, label + '-' + width + '.png'), fullPage: true, animations: 'disabled' });
    };
    for (const width of [1440, 1024, 768, 375, 320]) {
        await page.setViewportSize({ width, height: width <= 375 ? 800 : 960 });
        await page.goto(listPath + '?search=' + product.sku);
        await expect(page.getByRole('heading', { name: 'Átmozgatási igények' })).toBeVisible();
        await expect(page.getByText(longProduct, { exact: true }).filter({ visible: true }).first()).toBeVisible();
        if (width === 1440) {
            const table = page.getByRole('table', { name: 'Átmozgatási igények' });
            const headings = table.getByRole('columnheader');
            const cells = table.getByRole('rowgroup').getByRole('row').first().getByRole('cell');
            for (let column = 0; column < 4; column++) {
                const heading = await headings.nth(column).boundingBox();
                const cell = await cells.nth(column).boundingBox();
                expect(Math.abs(heading.x - cell.x), 'desktop column ' + (column + 1)).toBeLessThanOrEqual(1);
            }
        }
        await screenshot('igenylista', width);

        await page.goto(createPath);
        await fillRoute(page, { ...product, from: longSource, to: longDestination });
        await page.locator('#intent-quantity').fill('200');
        await page.getByRole('button', { name: 'Doboz', exact: true }).click();
        await expect(page.locator('#intent-box-count')).toHaveValue('2');
        await screenshot('uj-igeny', width);

        await page.goto(detailsPath(pending.id));
        await expect(page.locator('[data-intent-status]')).toHaveText('Jóváhagyásra vár');
        await screenshot('fuggo-adatlap', width);
        const reject = dialogAction(page, 'Elutasítás', 'Elutasítás indoka');
        await reject.open.click();
        await expect(reject.reason).toBeVisible();
        await reject.reason.fill('Az igényelt mennyiség egyeztetés alatt áll.');
        await screenshot('elutasitas', width);

        await page.goto(detailsPath(approved.id));
        await expect(page.locator('[data-intent-status]')).toHaveText('Jóváhagyva');
        await screenshot('jovahagyott-adatlap', width);
        const cancel = dialogAction(page, 'Visszavonás', 'Visszavonás indoka');
        await cancel.open.click();
        await expect(cancel.reason).toBeVisible();
        await cancel.reason.fill('A jóváhagyás óta módosult a szerelési ütemterv.');
        await screenshot('visszavonas', width);

        await page.goto(listPath + '?search=egy-nem-letezo-igenyazonosito');
        await expect(page.getByText('Nincs a szűrésnek megfelelő igény.', { exact: true })).toBeVisible();
        await screenshot('ures-lista', width);

        await page.goto(createPath);
        await page.getByRole('button', { name: 'Igény benyújtása' }).click();
        await expect(page.getByText(/válassz.*terméket|termék.*kötelező/i).filter({ visible: true }).first()).toBeVisible();
        await screenshot('validacios-hiba', width);
    }
    expect(errors).toEqual([]);
});
