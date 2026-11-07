import { test, expect } from '@playwright/test';

async function login(page) {
    await page.goto('/login');
    await page.getByLabel('Email', { exact: true }).fill('warehouse-admin@example.test');
    await page.getByLabel('Password', { exact: true }).fill('browser-test-password');
    await page.getByRole('button', { name: 'Log in', exact: true }).click(); await expect(page).toHaveURL(/dashboard/);
}
const search = page => page.getByRole('link', { name: 'Globális gyorskereső', exact: true });

test('global search sits on the right and each list has a single page heading in a compact workspace', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 1000 }); await login(page);
    for (const path of ['dashboard', 'products', 'stock', 'movements', 'transfers', 'shipments', 'categories', 'warehouses', 'partners', 'users', 'quality-returns', 'search']) {
        await page.goto('/'+path);
        await expect(page.locator('main h1')).toHaveCount(1); await expect(page.locator('main h1')).toBeVisible();
        const topbar = page.locator('[data-app-topbar]');
        await expect(topbar.getByRole('heading')).toHaveCount(0);
        await expect(topbar.getByRole('navigation')).toHaveCount(path === 'warehouses' ? 1 : 0);
        await expect(search(page)).toBeVisible();
        const bar = await topbar.boundingBox(); const field = await search(page).boundingBox();
        expect(field.x, path).toBeGreaterThan(bar.x + bar.width / 2); expect(bar.height, path).toBeLessThanOrEqual(60);
        const title = await page.locator('main h1').boundingBox();
        expect(title.y - (bar.y + bar.height), path+' title should start close to the global bar').toBeLessThanOrEqual(32);
        if (['dashboard', 'products', 'stock'].includes(path)) await page.screenshot({ path: 'tests/Browser/.runtime/compact-'+path+'-desktop.png', fullPage: true });
    }
});

test('search and the compact header fit narrow screens and enlarged text while shortcuts and menu still work', async ({ page }) => {
    const errors = []; page.on('pageerror', error => errors.push(error.message));
    await login(page); await page.goto('/stock');
    for (const font of ['16px', '24px']) {
        await page.evaluate(font => { document.documentElement.style.fontSize = font; }, font);
        for (const width of [320, 390, 768, 1024, 1440]) {
            await page.setViewportSize({ width, height: 800 });
            expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), width+'px at '+font).toBe(true);
            const bounds = await search(page).boundingBox(); expect(bounds.width).toBeGreaterThanOrEqual(44);
            await expect(page.locator('main h1')).toBeVisible();
        }
    }
    await page.evaluate(() => { document.documentElement.style.fontSize = ''; });
    await page.setViewportSize({ width: 390, height: 800 }); await page.goto('/stock');
    await page.screenshot({ path: 'tests/Browser/.runtime/compact-stock-mobile.png', fullPage: true });
    await search(page).click(); await expect(page.getByRole('dialog', { name: 'Gyorskereső', exact: true })).toBeVisible();
    await page.keyboard.press('Escape'); await expect(search(page)).toBeFocused();
    await page.keyboard.press('Control+k'); await expect(page.getByRole('dialog', { name: 'Gyorskereső', exact: true })).toBeVisible();
    await page.keyboard.press('Escape'); await page.locator('[data-navigation-toggle]').click();
    await expect(page.getByRole('dialog', { name: 'Készletkezelő', exact: true })).toBeVisible();
    await page.keyboard.press('Escape'); expect(errors).toEqual([]);
});

test('legacy header forms gain usable Back links and each detail keeps one main title', async ({ page }) => {
    await login(page);
    for (const [path, back, target] of [
        ['/products/create', '← Termékek', '/products'],
        ['/products/1/edit', '← Termékek', '/products'],
        ['/users/create', '← Felhasználók', '/users'],
        ['/products/1', '← Termékek', '/products'],
        ['/warehouses/1', '← Raktárak', '/warehouses'],
    ]) {
        await page.goto(path); await expect(page.locator('main h1')).toHaveCount(1);
        const link = page.locator('main').getByRole('link', { name: back, exact: true }); await expect(link).toHaveCount(1);
        await link.click(); await expect(page).toHaveURL(url => url.pathname === target);
    }
    await page.goto('/profile'); await expect(page.getByRole('heading', { name: 'Profil', exact: true, level: 1 })).toBeVisible();
});
