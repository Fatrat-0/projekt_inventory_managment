import { expect, test } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { randomUUID } from 'node:crypto';

function fixture(...args) {
    const result = execFileSync('php', ['tests/Browser/warehouse-load-check-fixture.php', ...args], { encoding: 'utf8' });
    try { return JSON.parse(result); } catch { throw new Error(result); }
}
const measurement = page => page.getByLabel('Mért teljes bruttó tömeg (kg)', { exact: true });
const slots = page => page.getByLabel('Tényleges raklaphelyigény', { exact: true });
const inspect = page => page.getByRole('button', { name: 'Mérés ellenőrzése', exact: true });
const review = page => page.getByRole('button', { name: 'Tovább a megerősítéshez', exact: true });
const dialog = page => page.getByRole('dialog', { name: 'Helyszíni mérés megerősítése' });
const recorded = 'A mérést rögzítettük és az ellenőrzést lezártuk.';
const discrepancy = 'A mérést rögzítettük. A terhelési eltérés továbbra is nyitott.';
const holdReason = 'Nyitott eltérés miatt nem használható új elhelyezésre.';
const notice = page => page.locator('[data-load-notice]');
const capacityDetails = page => page.locator('[data-load-capacity-comparison]');
const capacityPhase = (page, scope, phase) => capacityDetails(page).locator('[data-capacity-scope="' + scope + '"] [data-capacity-phase="' + phase + '"]');
const capacityMetric = (phase, label) => phase.getByText(label, { exact: true }).locator('..').locator('dd');

async function login(page, email = 'warehouse-admin@example.test') {
    await page.goto('/login');
    await page.getByLabel('Email', { exact: true }).fill(email);
    await page.getByLabel('Password', { exact: true }).fill('browser-test-password');
    await page.getByRole('button', { name: 'Log in', exact: true }).click();
    await expect(page).toHaveURL(/dashboard/);
}

async function input(page, kg) {
    await measurement(page).fill(kg);
    await slots(page).fill('0');
}

async function confirm(page, critical = false) {
    await review(page).click();
    await expect(dialog(page).getByRole('heading')).toBeFocused();
    const save = dialog(page).getByRole('button', { name: critical ? 'Eltérés rögzítése' : 'Mérés rögzítése', exact: true });
    await expect(save).toBeDisabled();
    await expect(dialog(page).getByRole('checkbox')).not.toBeChecked();
    await dialog(page).getByRole('checkbox').check();
    await save.click();
}

async function json(page, url) {
    return (await page.request.get(url, { headers: { Accept: 'application/json' } })).json();
}

async function post(page, url, data) {
    const csrf = await page.locator('meta[name="csrf-token"]').getAttribute('content');
    return page.request.post(url, { data, headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf } });
}

async function widths(page, modal = false) {
    for (const width of [320, 375, 768, 1024, 1440]) {
        await page.setViewportSize({ width, height: width < 600 ? 630 : 850 });
        expect(await page.evaluate(() => document.documentElement.scrollWidth), 'viewport ' + width).toBeLessThanOrEqual(width);
        if (modal) {
            const box = await dialog(page).boundingBox();
            expect(box.x).toBeGreaterThanOrEqual(0);
            expect(box.x + box.width).toBeLessThanOrEqual(width + 1);
            expect(box.y + box.height).toBeLessThanOrEqual((width < 600 ? 630 : 850) + 1);
        }
    }
}

test('list, filtering, keyboard and responsive measurement save one audited physical observation', async ({ page }) => {
    test.setTimeout(90_000);
    const data = fixture('create', 'duplicates');
    await login(page);
    await page.goto(data.index);
    await expect(page.locator('[data-load-row]')).toHaveCount(3);
    const list = page.getByRole('table', { name: 'Ellenőrzendő rekesztételek' });
    for (const width of [320, 375, 768, 1024, 1440]) {
        await page.setViewportSize({ width, height: 850 });
        const columns = list.getByRole('columnheader').first();
        await expect(columns).toHaveJSProperty('textContent', 'Termék');
        const position = await columns.evaluate(element => element.closest('[role="row"]').getBoundingClientRect().height);
        expect(position > 2).toBe(width >= 1024);
        const caption = list.locator('[data-load-row] [aria-hidden="true"]').filter({ hasText: /^Darabszám$/ }).filter({ visible: true });
        await expect(caption).toHaveCount(width >= 1024 ? 0 : 3);
    }
    await widths(page);
    await page.getByRole('combobox', { name: 'Raktár' }).selectOption(String(data.foreign_id));
    await page.getByRole('button', { name: 'Keresés', exact: true }).click();
    await expect(page.getByText('Ebben a raktárban jelenleg nincs ellenőrzésre váró rekeszterhelés.')).toBeVisible();
    await page.goto(data.index);
    await page.getByRole('combobox', { name: 'Állapot' }).selectOption('pending');
    await page.getByRole('searchbox', { name: 'Termék vagy rekeszkód' }).fill(data.sku);
    await page.getByRole('searchbox', { name: 'Termék vagy rekeszkód' }).press('Enter');
    const matching = page.locator('[data-load-row]');
    await expect(matching).toHaveCount(2);
    const origin = matching.filter({ has: page.getByText(data.code, { exact: true }) });
    const other = matching.filter({ has: page.getByText(data.safe_code, { exact: true }) });
    const open = origin.getByRole('link', { name: 'Ellenőrzés megnyitása', exact: true });
    await expect(other.getByRole('link', { name: 'Ellenőrzés megnyitása', exact: true })).toBeVisible();
    await expect(open).toHaveAccessibleDescription(data.product + ' ' + data.code);
    await expect(open).toHaveAttribute('href', new RegExp(data.show + '$'));
    await open.focus();
    await page.keyboard.press('Enter');
    await expect(page.locator('[data-current-quantity]')).toHaveText('6 db');
    await expect(page.getByRole('link', { name: 'Megjelenítés az alaprajzon' })).toHaveAttribute('href', /[?&]bin=/);
    await widths(page);
    await expect(measurement(page)).toHaveValue('');
    await measurement(page).fill('6,25');
    await inspect(page).click();
    await expect(slots(page)).toBeFocused();
    await expect(page.getByText('Add meg a raklaphelyek számát. Ha nincs raklap, írj 0-t.')).toBeVisible();
    await slots(page).fill('0');
    await page.getByLabel('Megjegyzés').fill('Mérleggel ellenőrizve');
    await inspect(page).click();
    await expect(page.getByRole('heading', { name: 'Mérés előnézete' })).toBeFocused();
    await expect(page.getByText('10 kg → 6,25 kg')).toBeVisible();
    await capacityDetails(page).locator('summary').press('Enter');
    const after = capacityPhase(page, 'location_weight_grams', 'after');
    await expect(capacityMetric(after, 'Számított kapacitásmaradék')).toHaveText('3,75 kg');
    await expect(capacityMetric(after, 'Új elhelyezésre felhasználható')).toHaveText('3,75 kg');
    await expect(after.getByText(holdReason, { exact: true })).toBeHidden();
    await review(page).press('Enter');
    await expect(dialog(page).getByRole('heading')).toBeFocused();
    await widths(page, true);
    await page.keyboard.press('Escape');
    await expect(review(page)).toBeFocused();
    await confirm(page);
    await expect(notice(page)).toHaveText(recorded);
    await expect(notice(page)).toHaveClass(/bg-emerald-50/);
    await expect(page.locator('[data-open-load-warning]')).toBeHidden();
    await expect(page.locator('[data-load-status]')).toHaveText('Ellenőrzött');
    expect((await json(page, data.other_api)).placement).toMatchObject({ quantity: 2, recorded_weight_grams: 2000, status: 'pending' });
    await page.getByRole('link', { name: 'Mérési előzmények', exact: true }).click();
    await page.locator('[data-load-history] summary').click();
    await expect(page.getByText('Mérleggel ellenőrizve')).toBeVisible();
    await expect(page.getByText('10 kg → 6,25 kg')).toBeVisible();
});

test('plain 422 differs from an audited overload and an emptied row can close without touching its neighbour', async ({ page }) => {
    test.setTimeout(90_000);
    const data = fixture('create');
    await login(page);
    await page.goto(data.show);
    await input(page, '14');
    await inspect(page).click();
    await expect(page.getByText('A mérés dokumentálható, de az ellenőrzés nyitva marad.')).toBeVisible();
    await page.route('**' + data.api, route => route.request().method() === 'POST'
        ? route.fulfill({ status: 422, json: { errors: { cargo_weight_kg: ['A mérési érték ellenőrzése szükséges.'] } } }) : route.fallback());
    await confirm(page, true);
    await expect(page.getByText('A mérési érték ellenőrzése szükséges.')).toBeVisible();
    await expect(page.getByText(discrepancy)).toHaveCount(0);
    await expect(measurement(page)).toHaveValue('14');
    expect((await json(page, data.history)).total).toBe(0);
    await page.unroute('**' + data.api);

    await inspect(page).click();
    const audited = page.waitForResponse(response => response.url().endsWith(data.api) && response.request().method() === 'POST');
    await confirm(page, true);
    const response = await audited;
    expect(response.status()).toBe(422);
    expect(await response.json()).toMatchObject({ discrepancy_open: true, verification: { measured_weight_grams: 14000 } });
    await expect(notice(page)).toHaveText(discrepancy);
    await expect(notice(page)).toHaveClass(/bg-gray-50/);
    await expect(notice(page)).not.toHaveClass(/bg-(rose|emerald)-50/);
    const summary = page.locator('[data-open-load-warning]');
    await expect(summary).toHaveClass(/bg-rose-50/);
    await expect(page.locator('[data-load-status]')).toHaveText('Kritikus terhelési eltérés');
    const capacity = page.getByRole('group').filter({ has: page.getByText('Aktuális kapacitás', { exact: true }) });
    await expect(capacity.getByText(/polcszinten nyitott terhelési eltérés/)).toBeVisible();
    await expect(capacity.getByText(/tárolórendszerben nyitott terhelési eltérés/)).toBeVisible();
    await expect(capacity.getByText('Határ: 12 kg · Figyelembe vett terhelés: 16 kg', { exact: true })).toHaveCount(3);
    await expect(capacity.getByText('Új elhelyezésre használható: 0 kg', { exact: true })).toHaveCount(3);
    const replay = await post(page, data.api, response.request().postDataJSON());
    expect(replay.status()).toBe(422);
    expect((await replay.json()).verification.id).toBe((await response.json()).verification.id);
    expect((await json(page, data.history)).total).toBe(1);
    await expect(page.getByRole('status').filter({ hasText: discrepancy })).toHaveCount(1);
    await page.goto(data.index);
    await page.goBack();
    await expect(summary).toBeVisible();
    await expect(notice(page)).toBeHidden();
    await page.reload();
    await expect(summary).toBeVisible();
    await expect(notice(page)).toBeHidden();
    const blocked = await post(page, data.place, { request_key: randomUUID(), product_id: data.product_id, quantity: 1, cargo_weight_kg: 1 });
    expect(blocked.status()).toBe(422);
    expect((await blocked.json()).errors.to_location_id[0]).toContain('nyitott terhelési eltérés');
    await widths(page);

    await page.goto(data.bin);
    await expect(page.getByText(/További elhelyezésre nem használható/)).toBeVisible();
    await page.getByRole('link', { name: /Terhelés ellenőrzése/ }).last().click();
    const openRow = page.locator('[data-load-row]').filter({ has: page.getByText('Kritikus terhelési eltérés', { exact: true }) });
    await expect(openRow.getByRole('link', { name: 'Részletek', exact: true })).toHaveAttribute('href', new RegExp(data.show + '$'));

    fixture('issue', String(data.id), 'all');
    await page.goto(data.bin);
    await page.getByRole('link', { name: /Terhelés ellenőrzése/ }).last().click();
    await expect(page.locator('[data-load-row]')).toHaveCount(2);
    await page.getByRole('link', { name: data.product, exact: true }).click();
    await expect(page.locator('[data-current-quantity]')).toHaveText('0 db');
    await expect(measurement(page)).toBeHidden();
    await page.getByRole('button', { name: 'Új mérés indítása' }).click();
    await page.getByRole('button', { name: 'Üres állapot ellenőrzése', exact: true }).click();
    const closing = page.waitForResponse(r => r.url().endsWith(data.api) && r.request().method() === 'POST');
    await confirm(page);
    expect((await closing).request().postDataJSON()).toMatchObject({ cargo_weight_kg: 0, pallet_slots: 0, measurement_confirmed: true });
    await expect(page.getByText(recorded)).toBeVisible();
    await expect(page.locator('[data-load-status]')).toHaveText('Ellenőrzött');
    expect((await json(page, data.other_api)).placement.quantity).toBe(2);
    await page.getByRole('link', { name: 'Mérési előzmények', exact: true }).click();
    await page.locator('[data-load-history] summary').filter({ hasText: 'Dokumentált terhelési eltérés' }).click();
    await expect(page.getByText(/Üres tétel igazolva/)).toBeVisible();
});

test('a 35 kg observation against 25 kg distinguishes confirmed overload from a safety hold', async ({ page }) => {
    test.setTimeout(80_000);
    const data = fixture('create', 'exact');
    await login(page);
    await page.goto(data.show);
    await expect(page.locator('[data-load-status]')).toHaveText('Ellenőrzésre vár');
    await expect(page.locator('[data-load-status]').locator('..')).toHaveClass(/bg-amber-50/);
    await input(page, '35');
    await inspect(page).click();
    await expect(page.getByText('Rekesz teherbírása túllépve. Túllépés: 10 kg.')).toBeVisible();
    await capacityDetails(page).locator('summary').press('Enter');
    const bin = capacityDetails(page).locator('[data-capacity-scope="location_weight_grams"]');
    const binAfter = capacityPhase(page, 'location_weight_grams', 'after');
    const levelBefore = capacityPhase(page, 'level_weight_grams', 'before');
    const levelAfter = capacityPhase(page, 'level_weight_grams', 'after');
    await expect(bin.getByText('Névleges határ: 25 kg', { exact: true })).toBeVisible();
    await expect(capacityMetric(capacityPhase(page, 'location_weight_grams', 'before'), 'Számított kapacitásmaradék')).toHaveText('15 kg');
    await expect(capacityMetric(binAfter, 'Figyelembe vett terhelés')).toHaveText('35 kg');
    await expect(capacityMetric(binAfter, 'Számított kapacitásmaradék')).toHaveText('Túllépés: 10 kg');
    await expect(capacityMetric(binAfter, 'Új elhelyezésre felhasználható')).toHaveText('0 kg');
    await expect(capacityMetric(levelBefore, 'Új elhelyezésre felhasználható')).toHaveText('74 kg');
    await expect(capacityMetric(levelAfter, 'Számított kapacitásmaradék')).toHaveText('49 kg');
    await expect(capacityMetric(levelAfter, 'Új elhelyezésre felhasználható')).toHaveText('0 kg');
    await expect(levelAfter.getByText(holdReason, { exact: true })).toBeVisible();
    await widths(page);
    await page.getByText('Részletes ellenőrzések', { exact: true }).click();
    const detailCheck = label => page.getByRole('listitem').filter({ has: page.getByText(label, { exact: true }) });
    await expect(detailCheck('Rekesz teherbírása')).toContainText('Nem felel meg');
    await expect(detailCheck('Polcszint teherbírása')).toContainText('Megfelel');
    await review(page).click();
    await expect(dialog(page).getByText('A megadott mérés kapacitáskorlát megsértését jelzi.')).toHaveClass(/font-semibold/);
    await expect(dialog(page).getByRole('button', { name: 'Eltérés rögzítése' })).toHaveClass(/bg-rose-700/);
    await dialog(page).getByRole('checkbox').check();
    await page.keyboard.press('Escape');
    await expect(review(page)).toBeFocused();
    await confirm(page, true);
    const summary = page.locator('[data-open-load-warning]');
    await expect(summary).toHaveClass(/bg-rose-50/);
    await expect(summary.getByText('+10 kg', { exact: true })).toBeVisible();
    await expect(page.getByText('Számított kapacitásmaradék: 49 kg', { exact: true })).toBeVisible();
    await expect(page.getByText('Új elhelyezésre használható: 0 kg', { exact: true }).first()).toBeVisible();
    await expect(page.getByRole('link', { name: 'Áthelyezés indítása' })).toHaveAttribute('href', new RegExp('from_location_id=' + data.source_id));
    await expect(page.getByRole('button', { name: 'Új mérés indítása' })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Mérési előzmények', exact: true })).toBeVisible();
    await widths(page);
    for (const width of [320, 375, 768, 1024, 1440]) {
        await page.setViewportSize({ width, height: 850 });
        const detail = await page.getByRole('region', { name: 'Rekesztétel adatai' }).boundingBox();
        const actions = await page.locator('[data-load-next]').boundingBox();
        if (width >= 1024) expect(actions.x).toBeGreaterThan(detail.x + detail.width);
        else expect(actions.y).toBeGreaterThan(detail.y + detail.height);
    }
    await page.getByRole('button', { name: 'Új mérés indítása' }).click();
    await input(page, '35');
    await inspect(page).click();
    await expect(capacityDetails(page)).toBeVisible();
    await expect(capacityDetails(page)).toHaveJSProperty('open', true);
    const binBefore = capacityPhase(page, 'location_weight_grams', 'before');
    await expect(capacityMetric(binBefore, 'Számított kapacitásmaradék')).toHaveText('Túllépés: 10 kg');
    await expect(capacityMetric(binAfter, 'Számított kapacitásmaradék')).toHaveText('Túllépés: 10 kg');
    await expect(capacityMetric(levelBefore, 'Számított kapacitásmaradék')).toHaveText('49 kg');
    await expect(capacityMetric(levelBefore, 'Új elhelyezésre felhasználható')).toHaveText('0 kg');
    await expect(levelBefore.getByText(holdReason, { exact: true })).toBeVisible();
    await page.getByRole('link', { name: 'Megjelenítés az alaprajzon' }).click();
    const planInspector = page.getByRole('complementary', { name: 'A tervrajz adatai' });
    await planInspector.getByText(/^1[.] szint · 2 hely/).click();
    await planInspector.getByText(data.code + ' · Kritikus terhelési eltérés', { exact: true }).click();
    await expect(planInspector.getByText('További elhelyezésre nem használható – nyitott terhelési eltérés miatt.', { exact: true })).toBeVisible();
    await page.goto(data.index);
    const row = page.locator('[data-load-row]').first();
    await expect(row).toHaveClass(/bg-rose-50/);
    await expect(row.getByText('Rekeszterhelés túllépve: +10 kg')).toBeVisible();
});

test('late preview and stale save require a fresh review while preserving the measurement draft', async ({ page }) => {
    test.setTimeout(80_000);
    const data = fixture('create');
    await login(page);
    await page.goto(data.show);
    let release;
    let markStarted;
    let markSettled;
    const settled = new Promise(resolve => { markSettled = resolve; });
    const deferred = new Promise(resolve => { release = resolve; });
    const started = new Promise(resolve => { markStarted = resolve; });
    await page.route('**' + data.preview, async route => {
        if (route.request().postDataJSON().cargo_weight_kg !== 5) return route.fallback();
        const response = await route.fetch();
        markStarted();
        await deferred;
        await route.fulfill({ response }).catch(() => {});
        markSettled();
    });
    await input(page, '5');
    await inspect(page).click();
    await started;
    await measurement(page).fill('7');
    await inspect(page).click();
    await expect(page.getByText('10 kg → 7 kg')).toBeVisible();
    await review(page).click();
    await expect(dialog(page).getByText('7 kg · 0 raklaphely')).toBeVisible();
    release();
    await settled;
    await expect(dialog(page).getByText('7 kg · 0 raklaphely')).toBeVisible();
    fixture('issue', String(data.id), 'part');
    const conflict = page.waitForResponse(r => r.url().endsWith(data.api) && r.request().method() === 'POST');
    await dialog(page).getByRole('checkbox').check();
    await dialog(page).getByRole('button', { name: 'Mérés rögzítése' }).click();
    expect((await conflict).status()).toBe(409);
    await expect(page.getByText(/A tétel időközben megváltozott/)).toBeVisible();
    await expect(page.locator('[data-current-quantity]')).toHaveText('5 db');
    await expect(measurement(page)).toHaveValue('7');
    await expect(review(page)).toBeHidden();
    await inspect(page).click();
    await confirm(page);
    await expect(page.getByText(recorded)).toBeVisible();
});

test('an overloaded shelf can be relieved to a safe bin and closed with a fresh measurement', async ({ page }) => {
    const data = fixture('create');
    await login(page);
    await page.goto(data.show);
    await input(page, '14');
    await inspect(page).click();
    await confirm(page, true);
    await expect(page.getByText(discrepancy)).toBeVisible();
    await page.getByRole('button', { name: 'Új mérés indítása' }).click();
    await input(page, '9');
    await inspect(page).click();
    await review(page).click();
    await expect(dialog(page).getByText('6 db')).toBeVisible();

    const move = await post(page, data.relocate, {
        warehouse_id: data.warehouse_id, product_id: data.product_id, from_location_id: data.source_id,
        to_location_id: data.safe_id, quantity: 2, cargo_weight_kg: 6, pallet_slots: 0,
        source_load_verified: true, site_verified: true, confirmed: true, operation_key: randomUUID(),
    });
    expect(move.status(), await move.text()).toBe(201);
    expect((await move.json()).relocation).toMatchObject({ quantity: 2, cargo_weight_grams: 6000, to_location_id: data.safe_id });
    await dialog(page).getByRole('checkbox').check();
    await dialog(page).getByRole('button', { name: 'Mérés rögzítése' }).click();
    await expect(page.getByText(/A tétel időközben megváltozott/)).toBeVisible();
    await expect(page.locator('[data-current-quantity]')).toHaveText('4 db');
    await expect(measurement(page)).toHaveValue('9');
    await expect(review(page)).toBeHidden();
    await expect(page.getByText(/A korábbi mérés nem igazolja a jelenlegi terhelést/)).toBeVisible();
    await expect(page.locator('[data-open-load-warning]')).toHaveClass(/bg-amber-50/);
    await expect(page.locator('[data-open-load-warning]').getByText('Túllépés', { exact: true })).toHaveCount(0);
    await input(page, '8');
    await inspect(page).click();
    await confirm(page);
    await expect(page.getByText(recorded)).toBeVisible();
    expect((await json(page, data.api)).placement).toMatchObject({ status: 'verified', quantity: 4, recorded_weight_grams: 8000 });
    expect((await json(page, data.other_api)).placement).toMatchObject({ status: 'pending', quantity: 2, recorded_weight_grams: 2000 });
    const history = await json(page, data.history);
    expect(history.total).toBe(2);
    expect(history.data[1]).toMatchObject({ measured_weight_grams: 14000, discrepancy_open: false,
        resolution: { method: 'remeasured', quantity: 4, measured_weight_grams: 8000 } });
});

test('a lost save response retries the exact UUID and operators can preview only in their warehouse', async ({ page }) => {
    test.setTimeout(80_000);
    const data = fixture('create');
    await login(page, data.operator);
    await page.goto(data.show);
    await input(page, '6');
    await inspect(page).click();
    await expect(page.getByText('A mérés rögzíthető és az ellenőrzés lezárható.')).toBeVisible();
    await expect(review(page)).toHaveCount(0);
    await page.goto(data.index);
    await expect(page.getByRole('combobox', { name: 'Raktár' }).locator('option')).toHaveCount(1);
    expect((await page.request.get(data.index + '?warehouse_id=' + data.foreign_id)).status()).toBe(403);
    await page.context().clearCookies();
    await login(page);
    await page.goto(data.show);
    await input(page, '6');
    await inspect(page).click();
    const requests = [];
    await page.route('**' + data.api, async route => {
        if (route.request().method() !== 'POST') return route.fallback();
        requests.push(route.request().postDataJSON());
        if (requests.length > 1) return route.fallback();
        const serverResponse = await route.fetch();
        expect(serverResponse.status()).toBe(201);
        await route.abort();
    });
    await confirm(page);
    await expect(dialog(page).getByText(/A mentés eredménye nem ellenőrizhető/)).toBeVisible();
    await dialog(page).getByRole('button', { name: 'Mentés újrapróbálása', exact: true }).click();
    await expect(page.getByText(recorded)).toBeVisible();
    expect(requests).toHaveLength(2);
    expect(requests[0]).toEqual(requests[1]);
    expect((await json(page, data.history)).total).toBe(1);

    const delayed = fixture('create');
    await page.goto(delayed.show);
    await input(page, '14');
    await inspect(page).click();
    const attempts = [];
    await page.route('**' + delayed.api, async route => {
        if (route.request().method() !== 'POST') return route.fallback();
        attempts.push(route.request().postDataJSON());
        if (attempts.length > 1) return route.fallback();
        expect((await route.fetch()).status()).toBe(422);
        await route.abort();
    });
    await confirm(page, true);
    await expect(dialog(page).getByText(/A mentés eredménye nem ellenőrizhető/)).toBeVisible();
    const { placement } = await json(page, delayed.api);
    const resolution = await post(page, delayed.api, {
        revision: placement.revision, cargo_weight_kg: 6, pallet_slots: 0, measurement_confirmed: true, operation_key: randomUUID(),
    });
    expect(resolution.status()).toBe(201);
    await dialog(page).getByRole('button', { name: 'Mentés újrapróbálása', exact: true }).click();
    await expect(notice(page)).toHaveText('A mérést rögzítettük.');
    await expect(notice(page)).toHaveClass(/bg-gray-50/);
    await expect(page.locator('[data-open-load-warning]')).toBeHidden();
    await expect(page.locator('[data-load-status]')).toHaveText('Ellenőrzött');
    expect(attempts).toHaveLength(2);
    expect(attempts[0]).toEqual(attempts[1]);
    expect((await json(page, delayed.history)).total).toBe(2);
});

test('replaying an earlier successful measurement cannot hide a newer open overload', async ({ page }) => {
    const data = fixture('create');
    await login(page);
    await page.goto(data.show);
    await input(page, '6');
    await inspect(page).click();
    const attempts = [];
    await page.route('**' + data.api, async route => {
        if (route.request().method() !== 'POST') return route.fallback();
        attempts.push(route.request().postDataJSON());
        if (attempts.length > 1) return route.fallback();
        expect((await route.fetch()).status()).toBe(201);
        await route.abort();
    });
    await confirm(page);
    await expect(dialog(page).getByText(/A mentés eredménye nem ellenőrizhető/)).toBeVisible();

    fixture('issue', String(data.id));
    const { placement } = await json(page, data.api);
    expect(placement).toMatchObject({ status: 'pending', quantity: 5 });
    const newer = await post(page, data.api, {
        revision: placement.revision, cargo_weight_kg: 14, pallet_slots: 0, measurement_confirmed: true, operation_key: randomUUID(),
    });
    expect(newer.status()).toBe(422);
    expect(await newer.json()).toMatchObject({ discrepancy_open: true });

    await dialog(page).getByRole('button', { name: 'Mentés újrapróbálása', exact: true }).click();
    await expect(notice(page)).toHaveText(discrepancy);
    await expect(page.locator('[data-open-load-warning]')).toBeVisible();
    await expect(page.locator('[data-load-status]')).toHaveText('Kritikus terhelési eltérés');
    await expect(page.locator('[data-current-quantity]')).toHaveText('5 db');
    await expect(page.getByRole('button', { name: 'Új mérés indítása' })).toBeVisible();
    expect(attempts).toHaveLength(2);
    expect(attempts[0]).toEqual(attempts[1]);
    expect((await json(page, data.history)).total).toBe(2);
});
