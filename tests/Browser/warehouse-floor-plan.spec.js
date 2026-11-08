import { test, expect } from '@playwright/test';

test('warehouse floor plan supports dragging, keyboard placement, collision feedback and saving', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto('/login');
    await page.getByLabel('Email', { exact: true }).fill('warehouse-admin@example.test');
    await page.getByLabel('Password', { exact: true }).fill('browser-test-password');
    await page.getByRole('button', { name: 'Log in', exact: true }).click();
    await expect(page).toHaveURL(/dashboard/);

    await page.goto('/warehouses/2');
    const csrf = await page.locator('meta[name="csrf-token"]').getAttribute('content');
    const create = async (code, name) => {
        const response = await page.request.post('/warehouses/2/locations', {
            headers: { 'X-CSRF-TOKEN': csrf }, form: { code, name, kind: 'shelf', max_weight_kg: '50' }, maxRedirects: 0,
        });
        expect(response.status()).toBe(302);
        expect(response.headers().location).toMatch(/\/warehouses\/2\/locations\/\d+/);
    };
    await create('FLOOR-A', 'Bemutató polc A');
    await create('FLOOR-B', 'Bemutató polc B');

    const tab = page.getByRole('link', { name: 'Raktári tervrajz', exact: true });
    await tab.click();
    await expect(tab).toHaveAttribute('aria-current', 'page');
    await page.getByRole('button', { name: 'Tervrajz szerkesztése', exact: true }).click();
    await page.getByRole('button', { name: 'FLOOR-A elhelyezése', exact: true }).click();
    await page.getByRole('button', { name: 'FLOOR-B elhelyezése', exact: true }).click();

    const tile = page.getByRole('button', { name: /FLOOR-A · Bemutató polc A/ });
    await tile.click();
    const x = page.getByLabel('Balról (X)', { exact: true });
    const y = page.getByLabel('Felülről (Y)', { exact: true });
    await x.fill('3');
    await expect(page.getByRole('alert')).toContainText('tárhely átfedi egymást');
    const save = page.getByRole('button', { name: 'Tervrajz mentése', exact: true });
    await expect(save).toBeDisabled();
    await x.fill('0');

    const board = page.locator('.warehouse-floor-grid');
    const cell = await board.evaluate(element => parseFloat(getComputedStyle(element).getPropertyValue('--floor-cell')));
    const box = await tile.boundingBox();
    await page.mouse.move(box.x + box.width / 2, box.y + box.height / 2);
    await page.mouse.down();
    await page.mouse.move(box.x + box.width / 2, box.y + box.height / 2 + cell * 3, { steps: 6 });
    await page.mouse.up();
    await expect(y).toHaveValue('3');
    await tile.press('ArrowRight');
    await expect(x).toHaveValue('1');
    await save.click();
    await expect(page.getByRole('status')).toContainText('A tervrajz mentve');

    await page.reload();
    await expect(tile).toBeVisible();
    await page.getByRole('button', { name: 'Tervrajz szerkesztése', exact: true }).click();
    await tile.click();
    await expect(x).toHaveValue('1');
    await expect(y).toHaveValue('3');

    const hint = page.getByText('Új rendszer hozzáadása vagy a teherbírás szerkesztése előtt mentsd az elrendezést.', { exact: true });
    await expect(hint).toBeHidden();
    const boardTop = () => board.evaluate(element => element.getBoundingClientRect().top);
    const initialTop = await boardTop();
    const savedTile = await tile.boundingBox();
    const mouseX = savedTile.x + savedTile.width / 2;
    const mouseY = savedTile.y + savedTile.height / 2;
    await page.mouse.move(mouseX, mouseY);
    await page.mouse.down();
    await page.mouse.move(mouseX, mouseY + cell * 0.65);
    await expect(hint).toBeVisible();
    expect(await boardTop()).toBeCloseTo(initialTop);
    await page.mouse.move(mouseX, mouseY + cell * 0.7);
    await expect(y).toHaveValue('4');
    await page.mouse.move(mouseX, mouseY - cell * 2);
    await expect(page.getByRole('alert')).toContainText('tárhely átfedi egymást');
    expect(await boardTop()).toBeCloseTo(initialTop);
    await page.mouse.move(mouseX, mouseY);
    await page.mouse.up();
    await expect(y).toHaveValue('3');
    await expect(hint).toBeHidden();
    await expect(page.getByRole('alert')).toBeHidden();
    expect(await boardTop()).toBeCloseTo(initialTop);

    await page.getByRole('button', { name: 'Mégse', exact: true }).click();

    await page.setViewportSize({ width: 320, height: 760 });
    await expect(page.getByRole('heading', { name: 'Felülnézet' })).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(320);
    await expect(page.getByRole('link', { name: 'Tárhely adatlapja', exact: true })).toHaveAttribute('href', /\/warehouses\/2\/locations\//);
});

test('warehouse designer creates twelve bins and reopens the rack for editing', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto('/login');
    await page.getByLabel('Email', { exact: true }).fill('warehouse-admin@example.test');
    await page.getByLabel('Password', { exact: true }).fill('browser-test-password');
    await page.getByRole('button', { name: 'Log in', exact: true }).click();
    await expect(page).toHaveURL(/dashboard/);
    await page.goto('/warehouses/4/floor-plan');
    const csrf = await page.locator('meta[name="csrf-token"]').getAttribute('content');
    const obstacle = await page.request.post('/warehouses/4/locations', {
        headers: { 'X-CSRF-TOKEN': csrf }, form: { code: 'WIZ-BLOCK', name: 'Meglévő polc', kind: 'shelf', max_weight_kg: '50' }, maxRedirects: 0,
    });
    expect(obstacle.status()).toBe(302);
    const obstacleId = Number(obstacle.headers().location.split('/').at(-1));
    const placed = await page.request.put('/warehouses/4/floor-plan', {
        headers: { 'X-CSRF-TOKEN': csrf }, data: { version: 1, columns: 24, rows: 16, positions: [{ id: obstacleId, x: 0, y: 0, width: 2, height: 2 }] },
    });
    expect(placed.ok()).toBe(true);
    await page.reload();
    await page.getByRole('button', { name: 'Polcos állvány hozzáadása', exact: true }).click();

    const wizard = page.getByRole('region', { name: 'Új tárolórendszer' });
    await expect(wizard.getByRole('button', { name: 'Polcos állvány', exact: true })).toHaveAttribute('aria-pressed', 'true');
    const next = wizard.getByRole('button', { name: 'Tovább', exact: true });
    await next.click();
    await wizard.getByLabel('Tárolórendszer neve').fill('Összeszerelő állvány');
    await expect(wizard.getByLabel('Tárolórendszer azonosítója')).toHaveValue('A-01');
    const preview = page.getByRole('region', { name: 'Állvány-előnézet' });
    const previewBins = preview.locator('[data-wizard-bin]');
    await expect(previewBins).toHaveCount(12);
    await expect(preview.getByRole('button', { name: /A-01-03-04/ })).toContainText('75 × 100 × 66 cm');
    const previewBin = preview.getByRole('button', { name: /A-01-03-04/ });
    await previewBin.click();
    await expect(previewBin).toHaveAttribute('aria-pressed', 'true');
    const levels = wizard.getByLabel('Szintek száma', { exact: true });
    const binsPerLevel = wizard.getByLabel('Tárolóhely szintenként', { exact: true });
    await levels.fill('2');
    await expect(previewBins).toHaveCount(8);
    await binsPerLevel.fill('5');
    await expect(previewBins).toHaveCount(10);
    await levels.fill('13');
    await expect(levels).toHaveAttribute('aria-invalid', 'true');
    await expect(next).toBeDisabled();
    await levels.fill('3');
    await binsPerLevel.fill('4');
    await expect(previewBins).toHaveCount(12);
    await page.setViewportSize({ width: 320, height: 760 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(320);
    await page.setViewportSize({ width: 1440, height: 900 });
    await next.click();
    await expect(wizard.getByLabel('Szintenként (kg)', { exact: true })).toHaveValue('150');
    const binLimit = wizard.getByLabel('Rekeszenkénti terhelési határ (kg)', { exact: true });
    await binLimit.fill('150');
    await wizard.getByText('Eltérő teherbírás szintenként', { exact: true }).click();
    await wizard.getByLabel('2. szint (kg)', { exact: true }).fill('200');
    await expect(preview.locator('[data-wizard-usable]')).toHaveText('400 kg');
    await expect(preview).toContainText(/1\s?800 kg/);
    await expect(preview).toContainText('Legalább egy szint határa kisebb');
    await expect(next).toBeEnabled();
    await wizard.getByRole('button', { name: 'Vissza', exact: true }).click();
    await expect(previewBins).toHaveCount(12);
    await next.click();
    await expect(binLimit).toHaveValue('150');
    await expect(wizard.getByLabel('2. szint (kg)', { exact: true })).toHaveValue('200');
    await next.click();
    await expect(wizard).toContainText('12 új tárolóhely jön létre.');
    const board = page.locator('.warehouse-floor-grid');
    await expect(board).toBeVisible();
    const cell = await board.evaluate(element => parseFloat(getComputedStyle(element).getPropertyValue('--floor-cell')));
    const submit = wizard.getByRole('button', { name: 'Létrehozás és elhelyezés' });
    const ghost = board.locator('[data-placement-preview]');
    const viewport = page.getByLabel('Görgethető raktári rács');
    const target = (x, y) => ({ position: { x: cell * (x + 0.5), y: cell * (y + 0.5) } });
    const ghostGeometry = () => ghost.evaluate(element => [element.style.left, element.style.top, element.style.width, element.style.height]);
    const writes = [];
    let releaseSave;
    const saveGate = new Promise(resolve => { releaseSave = resolve; });
    await page.route('**/warehouses/4/floor-plan/systems', async route => {
        writes.push(route.request().postDataJSON());
        await saveGate;
        await route.continue();
    });

    await expect(ghost).toHaveAttribute('data-placement-state', 'POSITIONING');
    await expect(submit).toBeDisabled();
    await board.hover(target(2, 2));
    const firstHover = await ghostGeometry();
    await board.hover(target(4, 3));
    await expect.poll(ghostGeometry).not.toEqual(firstHover);
    await expect(ghost).toHaveClass(/border-emerald-700/);
    await board.click({ position: { x: cell * 0.5, y: cell * 0.5 } });
    await expect(ghost).toHaveAttribute('data-invalid', 'true');
    await expect(ghost).toHaveClass(/border-rose-700/);
    await expect(ghost).toHaveAttribute('data-placement-state', 'POSITIONING');
    await expect(submit).toBeDisabled();
    await expect(wizard.getByRole('status')).toContainText('átfed');
    await wizard.getByLabel('Balról (X)').fill('99');
    await expect(wizard.getByRole('status')).toContainText('kilóg');
    await expect(submit).toBeDisabled();

    await board.click(target(4, 3));
    await expect(ghost).toHaveAttribute('data-placement-state', 'POSITION_SELECTED');
    await expect(wizard.getByRole('status')).toHaveText('Pozíció rögzítve');
    await expect(submit).toBeEnabled();
    await expect(viewport).toBeFocused();
    const pinned = await ghostGeometry();
    await board.hover(target(8, 5));
    await board.click(target(8, 5));
    await viewport.press('r');
    expect(await ghostGeometry()).toEqual(pinned);
    await expect(wizard.getByLabel('Balról (X)')).toHaveValue('4');
    await expect(wizard.getByLabel('Felülről (Y)')).toHaveValue('3');
    await expect(wizard.getByLabel('Balról (X)')).not.toBeEditable();
    expect(writes).toHaveLength(0);

    await wizard.getByRole('button', { name: 'Pozíció módosítása' }).click();
    await expect(ghost).toHaveAttribute('data-placement-state', 'POSITIONING');
    await expect(submit).toBeDisabled();
    await board.hover(target(2, 2));
    await expect.poll(ghostGeometry).not.toEqual(pinned);
    await viewport.press('Escape');
    await expect(ghost).toHaveCount(0);
    await expect(binLimit).toHaveValue('150');
    await next.click();
    await expect(ghost).toHaveCount(1);

    await wizard.getByLabel('Balról (X)').fill('1');
    await wizard.getByLabel('Felülről (Y)').fill('2');
    await viewport.focus();
    await viewport.press('ArrowRight');
    await expect(wizard.getByLabel('Balról (X)')).toHaveValue('2');
    await viewport.press('r');
    await expect.poll(async () => (await ghost.boundingBox()).height / (await ghost.boundingBox()).width).toBeCloseTo(3, 1);
    await viewport.press('Enter');
    await expect(ghost).toHaveAttribute('data-placement-state', 'POSITION_SELECTED');
    const finalPosition = await ghostGeometry();
    expect(writes).toHaveLength(0);
    try {
        await submit.click();
        await expect(ghost).toHaveAttribute('data-placement-state', 'SAVING');
        await expect(wizard.getByRole('button', { name: 'Mentés…' })).toBeDisabled();
        await board.hover(target(7, 5));
        expect(await ghostGeometry()).toEqual(finalPosition);
        await expect.poll(() => writes.length).toBe(1);
        expect(writes[0]).toMatchObject({ x: 2, y: 2, width: 2, height: 6, rotated: true });
    } finally {
        releaseSave();
    }
    await expect(page).toHaveURL(/\?system=\d+$/);
    await expect(ghost).toHaveCount(0);
    await expect(page.locator('[data-floor-tile^="system-"]')).toHaveCount(1);
    const summary = page.getByRole('region', { name: 'Létrehozási összefoglaló' });
    await expect(summary).toContainText('12 tárolóhely · 400 kg');
    const planUrl = page.url();
    await summary.getByRole('link', { name: 'Új állvány megnyitása' }).click();
    await expect(page.locator('[data-rack-bin]')).toHaveCount(12);
    await expect(page.getByRole('region', { name: '2. szint', exact: true })).toContainText('/ 200 kg');
    await expect(page.getByRole('region', { name: /A-01-01-01/ })).toContainText('150 kg');
    await page.goto(planUrl);

    const tile = page.getByRole('button', { name: /Összeszerelő állvány ·/ });
    await expect(tile).toHaveAttribute('aria-pressed', 'true');
    await expect(page.getByText('Szintek és tárolóhelyek')).toBeVisible();
    for (const number of [1, 2, 3]) {
        const summary = page.locator('summary').filter({ hasText: `${number}. szint · 4 hely` });
        await summary.click();
        await expect(summary.locator('..').locator('summary').filter({ hasText: /A-01-\d{2}-\d{2}/ })).toHaveCount(4);
    }
    await page.getByRole('button', { name: 'Tervrajz szerkesztése' }).click();
    const x = page.getByLabel('Balról (X)', { exact: true });
    await expect(x).toHaveValue('2');
    await expect(page.getByLabel('Felülről (Y)', { exact: true })).toHaveValue('2');
    await tile.press('ArrowRight');
    await expect(x).toHaveValue('3');
    await page.getByRole('button', { name: 'Visszavonás', exact: true }).click();
    await expect(x).toHaveValue('2');
    await page.getByRole('button', { name: 'Újra', exact: true }).click();
    await expect(x).toHaveValue('3');
    await page.getByRole('button', { name: 'Tervrajz mentése' }).click();
    await expect(page.getByRole('status')).toContainText('A tervrajz mentve');
    await page.reload();
    await expect(tile).toBeVisible();
    await page.getByRole('button', { name: 'Tulajdonságok szerkesztése' }).click();
    await page.getByLabel('Név', { exact: true }).fill('Kéziszerszám-állvány');
    await page.getByRole('button', { name: 'Tulajdonságok mentése' }).click();
    await expect(page.getByRole('button', { name: /Kéziszerszám-állvány ·/ })).toBeVisible();
    await page.getByRole('link', { name: 'Állvány megnyitása', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Állvány elölnézete' })).toBeVisible();
    await expect(page.locator('[data-rack-level]')).toHaveCount(3);
    await expect(page.getByRole('region', { name: '2. szint', exact: true })).toContainText('/ 200 kg');
    for (const row of await page.locator('[data-rack-level]').all()) {
        await expect(row.locator('[data-rack-bin]')).toHaveCount(4);
    }
    await page.getByRole('link', { name: /A-01-02-03/ }).click();
    const bin = page.getByRole('region', { name: /A-01-02-03/ });
    await expect(bin).toContainText('A tárhely üres.');
    await bin.getByText('Rekesz beállításainak szerkesztése').click();
    await bin.getByLabel('Szélesség (cm)').fill('70');
    await bin.getByLabel('Teherbírás (kg)').fill('85');
    await bin.getByLabel('Browser', { exact: true }).check();
    await bin.getByRole('button', { name: 'Rekesz mentése' }).click();
    await expect(bin).toContainText('70 × 100 × 66 cm');
    await expect(bin).toContainText('85 kg');
    await expect(bin).toContainText('Browser');
    await page.setViewportSize({ width: 320, height: 760 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(320);

    await page.goto('/warehouses/4/locations');
    await expect(page.getByText('A-01-02-03', { exact: true })).toHaveCount(0);
    const compact = page.locator('[data-storage-summary]').filter({ hasText: 'A-01 · Kéziszerszám-állvány' });
    await expect(compact).toContainText('400 kg szabad teherbírás');
    await expect(compact.getByRole('link', { name: 'Megnyitás', exact: true })).toBeVisible();
    await expect(compact.getByRole('link', { name: 'Megjelenítés a tervrajzon' })).toHaveAttribute('href', /floor-plan\?system=\d+$/);
    await page.locator('summary').filter({ hasText: 'A-01 · Kéziszerszám-állvány' }).click();
    await page.locator('summary').filter({ hasText: '2. szint · 4 tárhely' }).click();
    await page.locator('summary').filter({ hasText: 'A-01-02-03' }).click();
    await expect(page.getByText('85 kg', { exact: true }).last()).toBeVisible();

    await page.goto('/warehouses/4/floor-plan');
    await page.setViewportSize({ width: 320, height: 760 });
    await expect(page.getByRole('heading', { name: 'Felülnézet' })).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(320);
});
