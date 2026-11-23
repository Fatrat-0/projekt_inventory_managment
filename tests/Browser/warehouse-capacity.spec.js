import { expect, test } from '@playwright/test';
import { legacyDraft, shipmentCommand as command } from './shipment-fixtures';

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
    await expect(destination).toBeHidden();
    await page.getByRole('button', { name: 'Kézi célhelyválasztás' }).click();
    await destination.fill('R-01');
    await page.getByRole('option', { name: /R-01 · Beérkező raklapok/ }).click();
    await page.getByLabel('Mennyiség (db)').fill('4');
    await page.getByLabel('Raklapigény', { exact: true }).selectOption('pallet');
    await page.getByLabel('Szükséges raklaphelyek').fill('3');
    await page.getByLabel('Teljes tömeg (kg)').fill('60');
    await page.getByRole('button', { name: 'Átmozgatás indítása', exact: true }).click();
    await expect(page.getByRole('alert')).toContainText('Rekesz raklapférőhelye túllépve. Túllépés: 1 raklaphely.');
    await expect(page.locator('input[name="to_location_id"]')).toHaveValue(location);
    await expect(page.getByRole('region', { name: /Átmozgatás összegzése/ })).toContainText('R-01 · Beérkező raklapok');
    await expect(page.getByLabel('Teljes tömeg (kg)')).toHaveValue('60');

    await expect(page.getByLabel('Raklapigény', { exact: true })).toHaveValue('pallet');
    await page.getByLabel('Szükséges raklaphelyek').fill('2');
    await page.getByRole('button', { name: 'Tömeg megerősítése', exact: true }).click();
    await page.getByRole('button', { name: 'Ajánlások megjelenítése' }).click();
    await page.getByRole('button', { name: 'Ajánlott tárolóhelyek keresése' }).click();
    await page.getByRole('button', { name: 'Tárhely kiválasztása: R-01' }).click();
    await expect(page.getByText('Betárolás után várhatóan 40 kg és 0 raklaphely marad szabadon.')).toBeVisible();
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

    const shipmentPath = await legacyDraft(page, 'Tárhelyes próbaterv', 1, [{
        warehouse_id: 2, items: [{ product_id: 1, quantity: 1, to_location_id: Number(location), cargo_weight_kg: 15, pallet_slots: 1 }],
    }]);
    await page.goto(shipmentPath);
    await expect(page.getByRole('list', { name: 'Felrakodási sorrend' })).toContainText('15 kg');

    const created = await command(page, 'POST', '/warehouses/4/locations', {
        code: 'CLOSED', name: 'Lezárt polc', kind: 'shelf', max_weight_kg: 50,
    }, 302);
    const inactiveUrl = new URL(created.headers().location, page.url()).pathname;
    expect(inactiveUrl).toMatch(/\/warehouses\/4\/locations\/\d+$/);
    await command(page, 'PATCH', inactiveUrl, {
        code: 'CLOSED', name: 'Lezárt polc', kind: 'shelf', max_weight_kg: 50, active: 0, lock_version: 1,
    }, 302);
    await command(page, 'PUT', shipmentPath, {
        reference: 'Tárhelyes próbaterv', origin_warehouse_id: 1, version: 1,
        stops: [{ warehouse_id: 4, items: [{ product_id: 1, quantity: 1 }] }],
    }, 302);
    const rejected = await command(page, 'POST', shipmentPath+'/start', { version: 2 }, 422);
    expect((await rejected.json()).message).toContain('A célraktárhoz válassz aktív tárhelyet.');
});
