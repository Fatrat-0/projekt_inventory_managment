import { test, expect } from '@playwright/test';

async function create(page, name, parent = '') {
    await page.goto('/categories/create');
    await page.getByLabel('Kategória neve', { exact: true }).fill(name);
    if (parent) await page.getByLabel('Szülő kategória', { exact: true }).selectOption(parent);
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
    for (const id of [root, child, leaf]) await expect(page.locator('select[name="parent_id"] option[value="'+id+'"]')).toHaveCount(0);
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
    const button = form.getByRole('button');
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
