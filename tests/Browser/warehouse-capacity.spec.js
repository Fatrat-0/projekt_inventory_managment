import { expect, test } from '@playwright/test';

async function choose(page, label, query, option) {
    await page.getByRole('combobox', { name: label, exact: true }).fill(query);
    await page.getByRole('option', { name: option, exact: true }).click();
}

test('pallet capacity is reserved and received, and shipment drafts retain the selected location', async ({ page }) => {
    page.on('dialog', dialog => dialog.accept());
    await page.goto('/login');
    await page.getByLabel('Email', { exact: true }).fill('warehouse-admin@example.test');
    await page.getByLabel('Password', { exact: true }).fill('browser-test-password');
    await page.getByLabel('Password', { exact: true }).press('Enter');
    await expect(page).toHaveURL(/dashboard/);

    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/warehouses/2');
    const actions = page.locator('[data-app-topbar]').getByRole('navigation', { name: 'Raktári nézetek', exact: true });
    const capacityLink = actions.getByRole('link', { name: 'Tárhelyek és kapacitás', exact: true });
    const search = page.getByRole('link', { name: 'Globális gyorskereső', exact: true });
    await expect(actions.getByRole('link', { name: 'PDF-leltárív', exact: true })).toBeVisible();
    await expect(actions.getByRole('link', { name: 'Raktári áttekintés' })).toHaveAttribute('aria-current', 'page');
    for (const width of [320, 390, 1440]) {
        await page.setViewportSize({ width, height: 844 });
        const linkBounds = await capacityLink.boundingBox();
        const searchBounds = await search.boundingBox();
        expect(linkBounds.width).toBeGreaterThanOrEqual(44);
        if (width === 1440) expect(linkBounds.x + linkBounds.width).toBeLessThan(searchBounds.x);
        else expect(linkBounds.y).toBeGreaterThan(searchBounds.y);
        expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(width);
    }
    await page.setViewportSize({ width: 390, height: 844 });
    await capacityLink.click();
    await expect(capacityLink).toHaveAttribute('aria-current', 'page');
    await expect(page.getByRole('link', { name: 'Új tárolórendszer a tervrajzon' })).toHaveAttribute('href', /create=1/);
    const legacyCsrf = await page.locator('meta[name="csrf-token"]').getAttribute('content');
    const legacy = await page.request.post('/warehouses/2/locations', { headers: { 'X-CSRF-TOKEN': legacyCsrf },
        form: { code: 'R-01', name: 'Beérkező raklapok', kind: 'pallet', max_weight_kg: '100', max_pallet_slots: '2' }, maxRedirects: 0 });
    expect(legacy.status()).toBe(302);
    await page.goto(legacy.headers().location);
    await expect(page.getByText('2 / 2 szabad raklaphely')).toBeVisible();
    await expect(capacityLink).toHaveAttribute('aria-current', 'page');
    await expect(actions.getByRole('link', { name: 'PDF-leltárív', exact: true })).toBeVisible();
    const locationUrl = page.url();
    const location = new URL(locationUrl).pathname.split('/').at(-1);
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(390);
    await page.setViewportSize({ width: 1280, height: 900 });

    await page.goto('/transfers/create');
    await choose(page, 'Termék', 'ALPHA', 'Alpha teszttermék (ALPHA-001)');
    await choose(page, 'Forrásraktár', 'Teszt raktár', 'Teszt raktár · Test');
    const destination = page.getByRole('combobox', { name: 'Cél tárhely', exact: true });
    await expect(destination).toBeHidden();
    await choose(page, 'Célraktár', 'Északi', 'Északi tesztraktár · Bemutató célállomás');
    await expect(destination).toContainText('R-01 · Beérkező raklapok');
    await destination.selectOption(location);
    await page.getByLabel('Mennyiség (db)').fill('4');
    await page.getByLabel('Teljes tömeg (kg)').fill('60');
    await page.getByLabel('Raklapok száma').fill('3');
    await page.getByRole('button', { name: 'Átmozgatás indítása', exact: true }).click();
    await expect(page.getByRole('alert')).toContainText('R-01 tárhelyen 2 szabad raklaphely van');
    await expect(destination).toHaveValue(location);
    await expect(page.getByLabel('Teljes tömeg (kg)')).toHaveValue('60');

    await page.getByLabel('Raklapok száma').fill('2');
    await page.getByRole('button', { name: 'Átmozgatás indítása', exact: true }).click();
    await expect(page.getByRole('status')).toContainText('Átmozgatás elindítva');
    const transfersUrl = page.url();
    await page.goto(locationUrl);
    const capacity = page.getByRole('region', { name: 'Tárhelykapacitás' });
    await expect(capacity).toContainText('0 használt · 2 lefoglalt');

    await page.goto(transfersUrl);
    const row = page.getByRole('row').filter({ has: page.getByRole('link', { name: 'Alpha teszttermék', exact: true }) }).first();
    await row.getByRole('button', { name: 'Átvétel', exact: true }).click();
    await expect(page.getByRole('status')).toContainText('Az átvétel rögzítve');
    await page.goto(locationUrl);
    await expect(capacity).toContainText('2 használt · 0 lefoglalt');
    await expect(page.getByRole('region', { name: 'Tárhelyen tárolt tételek' })).toContainText('ALPHA-001 · 4 db · 60 kg');

    await page.goto('/shipments/create');
    await page.getByLabel('Szállítmány hivatkozása').fill('Tárhelyes próbaterv');
    await page.getByRole('combobox', { name: 'Induló raktár', exact: true }).fill('Teszt raktár');
    await page.getByRole('listbox', { name: 'Raktártalálatok' }).getByRole('option', { name: 'Teszt raktár', exact: true }).click();
    const stop = page.getByRole('group', { name: '1. megálló', exact: true });
    await stop.getByLabel('Célraktár', { exact: true }).selectOption('2');
    await stop.getByLabel('Termék', { exact: true }).selectOption('1');
    const stopLocation = stop.getByRole('combobox', { name: 'Cél tárhely', exact: true });
    await stopLocation.selectOption(location);
    await stop.getByLabel('Célraktár', { exact: true }).selectOption('3');
    await expect(stopLocation).toBeHidden();
    await stop.getByLabel('Célraktár', { exact: true }).selectOption('2');
    await expect(stopLocation).toHaveValue('');
    await stopLocation.selectOption(location);
    await stop.getByLabel('Teljes tömeg (kg)').fill('15');
    await stop.getByLabel('Raklapok száma').fill('1');
    await page.getByRole('button', { name: 'Tervezet mentése' }).click();
    await expect(page).toHaveURL(/\/shipments\/\d+$/);
    await page.getByRole('link', { name: 'Terv szerkesztése' }).click();
    await expect(stopLocation).toHaveValue(location);
    await expect(stop.getByLabel('Teljes tömeg (kg)')).toHaveValue('15');
    await expect(stop.getByLabel('Raklapok száma')).toHaveValue('1');

    const csrf = await page.locator('meta[name="csrf-token"]').getAttribute('content');
    const headers = { 'X-CSRF-TOKEN': csrf };
    const created = await page.request.post('/warehouses/4/locations', { headers,
        form: { code: 'CLOSED', name: 'Lezárt polc', kind: 'shelf', max_weight_kg: '50' }, maxRedirects: 0 });
    const inactiveUrl = created.headers().location;
    expect(inactiveUrl).toMatch(/\/warehouses\/4\/locations\/\d+$/);
    const disabled = await page.request.patch(inactiveUrl, { headers,
        form: { code: 'CLOSED', name: 'Lezárt polc', kind: 'shelf', max_weight_kg: '50', active: '0', lock_version: '1' }, maxRedirects: 0 });
    expect(disabled.status()).toBe(302);
    await page.reload();
    await stop.getByLabel('Célraktár', { exact: true }).selectOption('4');
    await expect(stop.getByText('A célraktárban nincs aktív tárhely.')).toBeVisible();
    await expect(stopLocation).toBeHidden();
    const sent = page.waitForRequest(request => request.method() === 'POST' && /\/shipments\/\d+$/.test(new URL(request.url()).pathname));
    await page.getByRole('button', { name: 'Tervezet mentése' }).click();
    const submitted = new URLSearchParams((await sent).postData());
    expect([...submitted.keys()].some(name => name.startsWith('stops['))).toBe(false);
    expect(JSON.parse(submitted.get('stops_json'))[0]).toMatchObject({ warehouse_id: '4', items: [{ to_location_id: null }] });
    await expect(page).toHaveURL(/\/shipments\/\d+$/);
    await page.getByRole('button', { name: 'Szállítmány indítása' }).click();
    await expect(page.getByRole('alert')).toContainText('A célraktárhoz válassz aktív tárhelyet.');
});
