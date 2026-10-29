import { test, expect } from '@playwright/test';

async function create(page, name, parent = '') {
    await page.goto('/categories/create');
    await page.getByLabel('Kategória neve', { exact: true }).fill(name);
    if (parent) {
        await page.getByLabel('Szülő kategória', { exact: true }).fill('#'+parent);
        await page.getByRole('listbox', { name: 'Szülőkategória-találatok', exact: true }).getByRole('option', { name: new RegExp('· #'+parent+'$') }).click();
    }
    await page.getByRole('button', { name: 'Kategória mentése', exact: true }).click();
    await expect(page).toHaveURL(url => url.pathname === '/categories');
    await page.goto('/categories?search='+encodeURIComponent(name));
    return page.locator('tr[data-category-id]').filter({ has: page.locator('td:first-child > span', { hasText: name }) }).first().getAttribute('data-category-id');
}

test.beforeEach(async ({ page }) => {
    await page.goto('/login');
    await page.getByLabel('Email', { exact: true }).fill('warehouse-admin@example.test');
    await page.getByLabel('Password', { exact: true }).fill('browser-test-password');
    await page.getByRole('button', { name: 'Log in', exact: true }).click();
    await expect(page).toHaveURL(/dashboard/);
});

test('category search suggests exact categories and clearing the selected name restores free text', async ({ page }) => {
    const root = await create(page, 'CAT-SEARCH Gépek');
    const child = await create(page, 'CAT-SEARCH Akkus', root);
    await page.goto('/categories');
    const search = page.getByRole('combobox', { name: 'Kategóriák keresése', exact: true });
    await search.fill('akkus gepek');
    const list = page.getByRole('listbox', { name: 'Kategóriajavaslatok', exact: true });
    await expect(list.getByRole('option')).toHaveCount(1);
    await search.press('ArrowDown'); await search.press('Enter');
    await expect(page).toHaveURL(url => url.searchParams.get('category_id') === child);
    await expect(page.locator('tr[data-category-id]')).toHaveCount(1);
    await search.fill('CAT-SEARCH');
    await search.press('Escape'); await search.press('Enter');
    await expect(page).toHaveURL(url => !url.searchParams.get('category_id') && url.searchParams.get('search') === 'CAT-SEARCH');
    await expect(page.locator('tr[data-category-id]')).toHaveCount(2);
    await page.getByLabel('Kategória típusa', { exact: true }).selectOption('root');
    await expect(page.locator('tr[data-category-id]')).toHaveCount(1);
    await expect(page.locator('tr[data-category-id]')).toHaveAttribute('data-category-id', root);
    await page.getByLabel('Kategória típusa', { exact: true }).selectOption('');
    await page.screenshot({ path: 'tests/Browser/.runtime/categories-desktop.png', fullPage: true });
});

test('editing excludes the entire own branch and preserves list context through validation and save', async ({ page }) => {
    const root = await create(page, 'CAT-FORM Root');
    const child = await create(page, 'CAT-FORM Child', root);
    const leaf = await create(page, 'CAT-FORM Leaf', child);
    await page.goto('/categories?search=CAT-FORM&per_page=25&sort_by=category_name&sort_dir=desc');
    const row = page.locator('tr[data-category-id="'+root+'"]');
    await row.getByRole('button', { name: /^Műveletek:/ }).click();
    const menu = page.getByRole('menu', { name: 'CAT-FORM Root műveletei', exact: true });
    await expect(menu.getByRole('menuitem', { name: 'Törlés', exact: true })).toBeDisabled();
    await menu.getByRole('menuitem', { name: 'Szerkesztés', exact: true }).click();
    await page.getByLabel('Szülő kategória', { exact: true }).click();
    for (const id of [root, child, leaf]) await expect(page.getByRole('listbox', { name: 'Szülőkategória-találatok', exact: true }).getByRole('option', { name: new RegExp('· #'+id+'$') })).toHaveCount(0);
    await page.keyboard.press('Escape');
    const minimum = page.getByLabel('Termékenkénti minimum (db)', { exact: true });
    await minimum.fill('-1');
    await page.locator('form').filter({ has: page.locator('[name="category_name"]') }).evaluate(form => form.noValidate = true);
    await page.getByRole('button', { name: 'Kategória mentése', exact: true }).click();
    await expect(page.getByRole('alert')).toBeVisible();
    await expect(minimum).toHaveValue('-1');
    await expect(minimum).toHaveAttribute('aria-invalid', 'true');
    await minimum.fill('0');
    await page.getByRole('button', { name: 'Kategória mentése', exact: true }).click();
    await expect(page).toHaveURL(url => url.pathname === '/categories' && url.searchParams.get('search') === 'CAT-FORM' && url.searchParams.get('per_page') === '25' && url.searchParams.get('sort_dir') === 'desc');
    await expect(page.locator('tr[data-category-id="'+root+'"]')).toContainText('0 db');
});

test('column sort submits current edits and pagination size, with no desktop sorting dropdown', async ({ page }) => {
    await create(page, 'CAT-SORT Beta'); await create(page, 'CAT-SORT Alpha');
    await page.goto('/categories?per_page=25');
    await page.getByRole('combobox', { name: 'Kategóriák keresése', exact: true }).fill('CAT-SORT');
    await page.keyboard.press('Escape');
    await page.getByRole('columnheader', { name: 'Termékenkénti minimum', exact: true }).getByRole('link').click();
    await expect(page).toHaveURL(url => url.searchParams.get('search') === 'CAT-SORT' && url.searchParams.get('sort_by') === 'default_item_min_stock' && url.searchParams.get('per_page') === '25');
    await expect(page.locator('tr[data-category-id]')).toHaveCount(2);
    await expect(page.getByLabel('Rendezés', { exact: true })).not.toBeVisible();
    await page.getByRole('columnheader', { name: /Termékenkénti minimum/ }).getByRole('link').click();
    await expect(page).toHaveURL(url => url.searchParams.get('sort_dir') === 'desc');
});

test('unused leaf deletion clears its exact filter and menus support keyboard and cancellation', async ({ page }) => {
    const id = await create(page, 'CAT-DELETE Leaf');
    await page.goto('/categories?category_id='+id);
    const trigger = page.getByRole('button', { name: 'Műveletek: CAT-DELETE Leaf', exact: true });
    await trigger.focus(); await page.keyboard.press('ArrowDown');
    const menu = page.getByRole('menu', { name: 'CAT-DELETE Leaf műveletei', exact: true });
    await expect(menu.getByRole('menuitem', { name: 'Szerkesztés', exact: true })).toBeFocused();
    await page.keyboard.press('End');
    await expect(menu.getByRole('menuitem', { name: 'Törlés', exact: true })).toBeFocused();
    page.once('dialog', dialog => dialog.dismiss());
    await page.keyboard.press('Enter');
    await expect(page.locator('tr[data-category-id="'+id+'"]')).toHaveCount(1);
    page.once('dialog', dialog => dialog.accept());
    await menu.getByRole('menuitem', { name: 'Törlés', exact: true }).click();
    await expect(page).toHaveURL(url => url.pathname === '/categories' && !url.searchParams.has('category_id'));
    await expect(page.locator('tr[data-category-id="'+id+'"]')).toHaveCount(0);
});

test('category cards and form fit mobile and show ancestor paths', async ({ page }) => {
    const root = await create(page, 'CAT-MOBILE Szülő');
    const id = await create(page, 'CAT-MOBILE Alkategória hosszú magyar névvel', root);
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/categories?category_id='+id);
    const card = page.locator('article[data-category-id="'+id+'"]');
    await expect(card).toBeVisible(); await expect(card).toContainText('CAT-MOBILE Szülő /');
    await expect(page.getByLabel('Rendezés', { exact: true })).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await page.screenshot({ path: 'tests/Browser/.runtime/categories-mobile.png', fullPage: true });
    await card.getByRole('button', { name: /^Műveletek:/ }).click();
    await page.getByRole('menuitem', { name: 'Szerkesztés', exact: true }).click();
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await page.getByRole('link', { name: 'Mégse', exact: true }).click();
    await expect(page).toHaveURL(url => url.searchParams.get('category_id') === id);
});

test('restoring a cached form unlocks submit without clearing entered values', async ({ page }) => {
    await page.goto('/categories/create');
    await page.getByLabel('Kategória neve', { exact: true }).fill('CAT-RESTORED Form');
    const form = page.locator('form').filter({ has: page.locator('[name="category_name"]') });
    const button = form.locator('button[type="submit"]');
    // Simulate the frozen state restored from the browser's back/forward cache.
    await form.evaluate(el => { window.Alpine.$data(el).submitting = true; });
    await expect(button).toBeDisabled();
    await page.evaluate(() => window.dispatchEvent(new PageTransitionEvent('pageshow', { persisted: true })));
    await expect(button).toBeEnabled();
    await expect(page.getByLabel('Kategória neve', { exact: true })).toHaveValue('CAT-RESTORED Form');
    await button.click();
    await expect(page).toHaveURL(url => url.pathname === '/categories');
});

test('category actions close when changing between desktop and mobile layouts', async ({ page }) => {
    const id = await create(page, 'CAT-RESIZE Leaf');
    await page.goto('/categories?category_id='+id);
    await page.getByRole('button', { name: /^Műveletek:/ }).click();
    await expect(page.getByRole('menu')).toBeVisible();
    await page.setViewportSize({ width: 390, height: 844 });
    await expect(page.getByRole('menu')).toHaveCount(0);
    await page.getByRole('button', { name: /^Műveletek:/ }).click();
    await expect(page.getByRole('menu')).toBeVisible();
    await page.setViewportSize({ width: 1280, height: 800 });
    await expect(page.getByRole('menu')).toHaveCount(0);
});

test('parent picker searches full paths without accents, preserves selection after errors and clears to root', async ({ page }) => {
    const root = await create(page, 'CAT-PICK Gépek');
    const child = await create(page, 'CAT-PICK Akkus', root);
    await page.goto('/categories/create');
    await page.getByLabel('Kategória neve', { exact: true }).fill('CAT-PICK Új');
    const parent = page.getByRole('combobox', { name: 'Szülő kategória', exact: true });
    await parent.fill('CAT-PICK gepek akkus');
    await expect(page.getByRole('listbox', { name: 'Szülőkategória-találatok', exact: true }).getByRole('option')).toHaveCount(1);
    await parent.press('ArrowDown'); await parent.press('Enter');
    await expect(page.locator('input[name="parent_id"]')).toHaveValue(child);
    await expect(parent).toHaveValue('CAT-PICK Gépek / CAT-PICK Akkus · #'+child);
    await page.getByLabel('Kategória minimum (db)', { exact: true }).fill('-1');
    await page.locator('form').filter({ has: page.locator('[name="category_name"]') }).evaluate(form => form.noValidate = true);
    await page.getByRole('button', { name: 'Kategória mentése', exact: true }).click();
    await expect(page.getByRole('alert')).toBeVisible();
    await expect(parent).toHaveValue('CAT-PICK Gépek / CAT-PICK Akkus · #'+child);
    await expect(page.locator('input[name="parent_id"]')).toHaveValue(child);
    await page.getByLabel('Kategória minimum (db)', { exact: true }).fill('0');
    await parent.fill('NINCS-ILYEN-SZÜLŐ');
    await expect(page.locator('input[name="parent_id"]')).toHaveValue('');
    expect(await parent.evaluate(el => el.checkValidity())).toBe(false);
    await parent.press('Tab');
    await page.getByRole('button', { name: 'Kategória mentése', exact: true }).click();
    await expect(page).toHaveURL(/\/categories\/create$/);
    await page.getByRole('button', { name: 'Szülő kategória törlése a mezőből', exact: true }).click();
    await expect(parent).toHaveValue('');
    expect(await parent.evaluate(el => el.checkValidity())).toBe(true);
    await parent.press('Escape');
    await page.getByRole('button', { name: 'Kategória mentése', exact: true }).click();
    await expect(page).toHaveURL(url => url.pathname === '/categories');
    await page.goto('/categories?search=CAT-PICK&type=root');
    await expect(page.locator('tr[data-category-id]')).toHaveCount(2);
});

test('Enter on an empty optional parent creates a root rather than selecting the first suggestion', async ({ page }) => {
    await page.goto('/categories/create');
    await page.getByLabel('Kategória neve', { exact: true }).fill('CAT-ENTER Főkategória');
    const parent = page.getByRole('combobox', { name: 'Szülő kategória', exact: true });
    await parent.click();
    await expect(page.getByRole('listbox', { name: 'Szülőkategória-találatok', exact: true })).toBeVisible();
    await parent.press('Enter');
    await expect(page).toHaveURL(url => url.pathname === '/categories');
    await page.goto('/categories?search=CAT-ENTER&type=root');
    await expect(page.locator('tr[data-category-id]')).toHaveCount(1);
});

test('composition Enter finishes text input without choosing a parent prematurely', async ({ page }) => {
    const id = await create(page, 'CAT-IME Szülő');
    await page.goto('/categories/create');
    const parent = page.getByRole('combobox', { name: 'Szülő kategória', exact: true });
    await parent.fill('CAT-IME');
    for (const eventData of [{ isComposing: true }, { keyCode: 229 }]) {
        await parent.evaluate((el, data) => el.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true, ...data })), eventData);
        await expect(page.locator('input[name="parent_id"]')).toHaveValue('');
        await expect(parent).toHaveValue('CAT-IME');
        await expect(page.getByRole('listbox', { name: 'Szülőkategória-találatok', exact: true })).toBeVisible();
    }
    await parent.press('ArrowDown'); await parent.press('Enter');
    await expect(page.locator('input[name="parent_id"]')).toHaveValue(id);
});

test('a parent deleted while the form is open cannot silently turn the category into a root', async ({ page }) => {
    const id = await create(page, 'CAT-STALE Régi szülő');
    await page.goto('/categories/create');
    await page.getByLabel('Kategória neve', { exact: true }).fill('CAT-STALE Új kategória');
    const parent = page.getByRole('combobox', { name: 'Szülő kategória', exact: true });
    await parent.fill('#'+id);
    await page.getByRole('listbox', { name: 'Szülőkategória-találatok', exact: true }).getByRole('option', { name: new RegExp('· #'+id+'$') }).click();
    const token = await page.locator('meta[name="csrf-token"]').getAttribute('content');
    const response = await page.request.delete('/categories/'+id, { headers: { 'X-CSRF-TOKEN': token, Accept: 'application/json' }, maxRedirects: 0 });
    expect(response.status(), await response.text()).toBe(302);
    await page.getByRole('button', { name: 'Kategória mentése', exact: true }).click();
    await expect(page.getByRole('alert')).toBeVisible();
    await expect(parent).toHaveValue('A korábbi szülő nem választható. Válassz másikat, vagy töröld a mezőt.');
    expect(await parent.evaluate(el => el.checkValidity())).toBe(false);
    await page.getByRole('button', { name: 'Szülő kategória törlése a mezőből', exact: true }).click();
    await parent.press('Escape');
    await page.getByRole('button', { name: 'Kategória mentése', exact: true }).click();
    await expect(page).toHaveURL(url => url.pathname === '/categories');
});

for (const mobile of [false, true]) {
    test(`branch product quick link includes child products with no stock and preserves Back navigation (${mobile ? 'mobile' : 'desktop'})`, async ({ page }) => {
        const root = await create(page, 'CAT-LINK-'+mobile+' Szülő');
        const child = await create(page, 'CAT-LINK-'+mobile+' Gyerek', root);
        const token = await page.locator('meta[name="csrf-token"]').getAttribute('content');
        for (const [category, name] of [[root, 'CAT-LINK-'+mobile+' A termék'], [child, 'CAT-LINK-'+mobile+' B termék']]) {
            const response = await page.request.post('/products', { headers: { 'X-CSRF-TOKEN': token, Accept: 'application/json' }, form: { category_id: category, product_name: name, sku: name.replaceAll(' ', '-'), price: '1' } });
            expect(response.ok(), await response.text()).toBe(true);
        }
        await page.setViewportSize(mobile ? { width: 390, height: 844 } : { width: 1280, height: 800 });
        await page.goto('/categories/'+root+'/edit');
        const originalUrl = page.url();
        const link = page.getByRole('link', { name: 'Termékek az ágban: 2', exact: true });
        await expect(link).toBeVisible();
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
        await page.screenshot({ path: `tests/Browser/.runtime/category-quick-link-${mobile ? 'mobile' : 'desktop'}.png`, fullPage: true });
        await link.click();
        await expect(page).toHaveURL(url => url.pathname === '/products' && url.searchParams.get('category_id') === root);
        expect(new URL(page.url()).searchParams.get('stock_status')).toBeNull();
        await expect(page.locator('tbody tr')).toHaveCount(2);
        await expect(page.locator('tbody')).toContainText('CAT-LINK-'+mobile+' A termék');
        await expect(page.locator('tbody')).toContainText('CAT-LINK-'+mobile+' B termék');
        await page.goBack();
        await expect(page).toHaveURL(originalUrl);
        await expect(link).toBeVisible();
        await page.goto('/categories/'+child+'/edit');
        await expect(page.getByRole('link', { name: 'Termékek az ágban: 1', exact: true })).toBeVisible();
    });
}
