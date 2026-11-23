import { test, expect } from '@playwright/test';

async function login(page, email = 'warehouse-admin@example.test') {
    await page.goto('/login');
    await page.getByLabel('Email', { exact: true }).fill(email);
    await page.getByLabel('Password', { exact: true }).fill('browser-test-password');
    await page.getByRole('button', { name: 'Log in', exact: true }).click();
    await expect(page).toHaveURL(/dashboard/);
}

test('warehouse tasks have their own active navigation and accessible tabs at each breakpoint', async ({ page }) => {
    test.setTimeout(60_000);
    await page.setViewportSize({ width: 1440, height: 900 });
    await login(page);
    const desktopMenu = page.getByRole('navigation', { name: 'Főmenü', exact: true });
    const work = '/raktari-feladatok';
    const stockGroup = /^(Készlet|Készletmozgások|Raktári feladatok|Átmozgatások)$/;
    const labels = ['Készlet', 'Készletmozgások', 'Raktári feladatok', 'Átmozgatások'];
    await expect(desktopMenu.getByRole('link', { name: stockGroup })).toHaveText(labels);
    await desktopMenu.getByRole('link', { name: 'Raktári feladatok', exact: true }).click();
    await expect(page).toHaveURL(work + '/kitarolas');
    const tabs = page.locator('[data-app-topbar]').getByRole('navigation', { name: 'Raktári feladatok munkatípusai' });
    const transfers = page.getByRole('navigation', { name: 'Átmozgatások modul' });
    await expect(tabs.getByRole('link', { name: 'Áthelyezés', exact: true })).toHaveCount(0);
    for (const width of [1440, 1024, 768, 375, 320]) {
        await page.setViewportSize({ width, height: 800 });
        let menu = desktopMenu;
        const mobile = width < 1280;
        if (mobile) {
            await page.getByRole('button', { name: 'Navigációs menü', exact: true }).click();
            menu = page.getByRole('navigation', { name: 'Mobil főmenü', exact: true });
        }
        await expect(menu.getByRole('link', { name: stockGroup })).toHaveText(labels);
        await expect(menu.locator('[aria-current="page"]')).toHaveText('Raktári feladatok');
        if (mobile) await page.keyboard.press('Escape');
        for (const [label, path] of [['Kitárolás', 'kitarolas'], ['Átvétel', 'atvetel'], ['Betárolás', 'betarolas']]) {
            const tab = tabs.getByRole('link', { name: label, exact: true });
            await tab.click();
            await expect(page).toHaveURL(work + '/' + path);
            await expect(tab).toHaveAttribute('aria-current', 'page');
            await expect(tab.locator('[data-task-count]')).toHaveText(/^\d+$/);
            const icon = tab.locator('svg[aria-hidden="true"]');
            if (width >= 1024) await expect(icon).toBeVisible();
            else await expect(icon).toBeHidden();
            await expect(transfers).toHaveCount(0);
            await expect(page.locator('main [data-warehouse-task-tabs]')).toHaveCount(0);
            expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(width);
        }
    }
    await page.goto('/atmozgatasok/raktari-feladatok/betarolas?origin=manual&sort_by=received_at&sort_dir=asc');
    await expect(page).toHaveURL(url => url.pathname === work + '/betarolas'
        && url.searchParams.get('origin') === 'manual' && url.searchParams.get('sort_by') === 'received_at');
    await page.goto('/shipments');
    const transferTabs = page.locator('[data-app-topbar]').getByRole('navigation', { name: 'Átmozgatások modul' });
    await expect(transfers).toHaveCount(1);
    await expect(transferTabs.getByRole('link')).toHaveCount(3);
    await expect(transferTabs.getByRole('link', { name: 'Fuvarok és szállítmányok' })).toHaveAttribute('aria-current', 'page');
    await expect(tabs).toHaveCount(0);

    for (const width of [320, 375, 768, 1024, 1440]) {
        await page.setViewportSize({ width, height: 800 });
        await expect(transferTabs).toBeVisible();
        expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(width);
    }
    await transferTabs.getByRole('link', { name: 'Járművek' }).press('Enter');
    await expect(page).toHaveURL('/atmozgatasok/jarmuvek');
    await expect(transferTabs.getByRole('link', { name: 'Járművek' })).toHaveAttribute('aria-current', 'page');

    await page.goto('/shipments/create');
    await expect(transferTabs.getByRole('link', { name: 'Fuvarok és szállítmányok' })).toHaveAttribute('aria-current', 'page');
    await page.goto('/atmozgatasok/uj-igeny');
    await expect(transferTabs.getByRole('link', { name: 'Igények' })).toHaveAttribute('aria-current', 'page');
    await expect(page.getByRole('main').getByRole('navigation', { name: 'Átmozgatások modul' })).toHaveCount(0);
});

test('desktop sidebar groups links, marks the current section and remembers hiding across pages', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    await login(page);
    const nav = page.getByRole('navigation', { name: 'Főmenü', exact: true });
    await expect(nav).toBeVisible();
    await expect(nav.getByRole('link')).toHaveCount(10);
    await expect(nav.locator('[aria-current="page"]')).toHaveText('Vezérlőpult');
    await nav.getByRole('link', { name: 'Termékek', exact: true }).click();
    await expect(page).toHaveURL(/\/products$/);
    await expect(nav.locator('[aria-current="page"]')).toHaveText('Termékek');
    const before = await page.locator('.app-content').boundingBox();
    await expect(page.locator('[data-navigation-toggle]')).toBeHidden();
    await page.getByRole('complementary', { name: 'Oldalsáv', exact: true }).getByRole('button', { name: 'Oldalsáv elrejtése', exact: true }).click();
    await expect(nav).toBeHidden();
    await expect(page.getByRole('button', { name: 'Oldalsáv megjelenítése', exact: true })).toBeFocused();
    expect((await page.locator('.app-content').boundingBox()).width).toBeGreaterThan(before.width);
    await page.goto('/movements');
    await expect(nav).toBeHidden();
    await page.reload();
    await expect(nav).toBeHidden();
    await page.getByRole('button', { name: 'Oldalsáv megjelenítése', exact: true }).click();
    await expect(nav).toBeVisible();
    await expect(page.getByRole('button', { name: 'Oldalsáv elrejtése', exact: true })).toBeFocused();
    await expect(nav.locator('[aria-current="page"]')).toHaveText('Készletmozgások');
    await page.goto('/dashboard');
    await page.screenshot({ path: 'tests/Browser/.runtime/navigation-desktop.png' });
});

test('mobile drawer traps focus, restores it on Escape, closes on backdrop and navigates normally', async ({ page }) => {
    await page.setViewportSize({ width: 375, height: 740 });
    await login(page, 'browser@example.test');
    const trigger = page.getByRole('button', { name: 'Navigációs menü', exact: true });
    const dialog = page.getByRole('dialog', { name: 'Készletkezelő', exact: true });
    await expect(page.getByRole('navigation', { name: 'Főmenü', exact: true })).toBeHidden();
    await trigger.click();
    await expect(trigger).toHaveAttribute('aria-expanded', 'true');
    await expect(dialog).toBeVisible();
    await expect(dialog.getByRole('button', { name: 'Menü bezárása', exact: true })).toBeFocused();
    await expect(dialog.getByRole('link', { name: 'Felhasználók', exact: true })).toHaveCount(0);
    await expect(dialog.getByRole('link')).toHaveCount(7); // Six destinations and the brand link.
    for (let index = 0; index < 12; index++) {
        await page.keyboard.press('Tab');
        expect(await dialog.evaluate(element => element.contains(document.activeElement))).toBe(true);
    }
    await expect(page.locator('body')).toHaveCSS('overflow', 'hidden');
    await page.screenshot({ path: 'tests/Browser/.runtime/navigation-mobile.png' });
    await page.keyboard.press('Escape');
    await expect(dialog).toBeHidden();
    await expect(trigger).toBeFocused();
    await expect(trigger).toHaveAttribute('aria-expanded', 'false');
    await expect(page.locator('body')).not.toHaveCSS('overflow', 'hidden');
    await trigger.click();
    await page.mouse.click(365, 200);
    await expect(dialog).toBeHidden();
    await trigger.click();
    await dialog.getByRole('link', { name: 'Termékek', exact: true }).click();
    await expect(page).toHaveURL(/\/products$/);
    await expect(dialog).toBeHidden();
    await expect(page.locator('body')).not.toHaveCSS('overflow', 'hidden');
});

test('resizing closes mobile modal and short screens scroll the menu independently', async ({ page }) => {
    await page.setViewportSize({ width: 320, height: 420 });
    await login(page);
    const trigger = page.locator('[data-navigation-toggle]');
    const dialog = page.getByRole('dialog', { name: 'Készletkezelő', exact: true });
    await trigger.click();
    const nav = dialog.getByRole('navigation', { name: 'Mobil főmenü', exact: true });
    expect(await nav.evaluate(element => element.scrollHeight > element.clientHeight)).toBe(true);
    await nav.getByRole('link', { name: 'Felhasználók', exact: true }).focus();
    expect(await nav.evaluate(element => element.scrollTop)).toBeGreaterThan(0);
    await page.setViewportSize({ width: 1440, height: 900 });
    await expect(dialog).toBeHidden();
    await expect(page.locator('body')).not.toHaveCSS('overflow', 'hidden');
    await expect(trigger).toHaveAttribute('aria-expanded', 'true');
    await page.getByRole('navigation', { name: 'Főmenü', exact: true }).getByRole('link', { name: 'Felhasználók', exact: true }).focus();
    await page.setViewportSize({ width: 375, height: 900 });
    await expect(trigger).toBeFocused();
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.getByRole('button', { name: 'Oldalsáv elrejtése', exact: true }).click();
    await expect(trigger).toHaveAttribute('aria-expanded', 'false');
    await page.setViewportSize({ width: 375, height: 900 });
    await expect(dialog).toBeHidden();
    await trigger.click();
    await expect(dialog).toBeVisible();
    await dialog.getByRole('button', { name: 'Menü bezárása', exact: true }).click();
    await page.setViewportSize({ width: 1440, height: 900 });
    await expect(page.getByRole('navigation', { name: 'Főmenü', exact: true })).toBeHidden();
});

test('all main pages and forms fit narrow viewports and the smaller desktop workspace', async ({ page }) => {
    test.setTimeout(120000);
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await login(page);
    const paths = ['/dashboard', '/products', '/categories', '/warehouses', '/partners', '/stock', '/movements', '/transfers', '/shipments', '/users', '/quality-returns', '/profile'];
    for (const width of [320, 768, 1280, 1440]) {
        await page.setViewportSize({ width, height: 900 });
        for (const path of paths) {
            const response = await page.goto(path);
            expect(response.status(), path).toBe(200);
            expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), `${path} at ${width}px`).toBe(true);
            await expect(page.locator('[data-navigation-toggle]')).toHaveAttribute('aria-expanded', width >= 1280 ? 'true' : 'false');
        }
    }
    for (const width of [320, 1280]) {
        await page.setViewportSize({ width, height: 900 });
        for (const path of ['/products/create', '/categories/create', '/warehouses/create', '/partners/create', '/movements/create', '/transfers/create', '/shipments/create', '/users/create']) {
            expect((await page.goto(path)).status()).toBe(200);
            expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), path).toBe(true);
        }
    }
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto('/products');
    await page.screenshot({ path: 'tests/Browser/.runtime/navigation-products-desktop.png' });
    expect(errors).toEqual([]);
    await page.goto('/dashboard');
    await page.locator('a[href="#category-alerts"]').click();
    expect((await page.locator('#category-alerts').boundingBox()).y).toBeGreaterThanOrEqual(64);
});

test('related pages highlight their section, show one page heading and retain contextual Back links', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    await login(page);
    for (const [path, section, label, back] of [
        ['/shipments/create', 'Átmozgatások', 'Új fuvar tervezése', null],
        ['/quality-returns', 'Készletmozgások', 'Visszáru-minősítés', null],
        ['/products/1/edit', 'Termékek', 'Szerkesztés', '← Termékek'],
        ['/warehouses/1', 'Raktárak', 'Adatlap', '← Raktárak'],
        ['/warehouses/statistics', 'Raktárak', 'Statisztika', null],
    ]) {
        await page.goto(path);
        await expect(page.getByRole('navigation', { name: 'Főmenü', exact: true }).locator('[aria-current="page"]')).toHaveText(section);
        const modulePages = path.startsWith('/warehouses/') || path === '/quality-returns' || path === '/shipments/create';
        await expect(page.locator('[data-app-topbar] nav')).toHaveCount(modulePages ? 1 : 0);
        await expect(page.locator('main h1')).toHaveCount(1); await expect(page).toHaveTitle(new RegExp(label));
        if (back) {
            const href = await page.locator('main').getByRole('link', { name: back, exact: true }).first().getAttribute('href');
            expect((await page.request.get(href)).status()).toBe(200);
        }
    }
});

test('native account menu supports keyboard dismissal, profile and POST logout on mobile', async ({ page }) => {
    await page.setViewportSize({ width: 320, height: 740 });
    await login(page, 'browser@example.test');
    const token = await page.locator('meta[name="csrf-token"]').getAttribute('content');
    const longName = 'Raktáros '.repeat(20).trim();
    expect((await page.request.patch('/profile', { headers: { 'X-CSRF-TOKEN': token }, data: { name: longName, email: 'browser@example.test' }, maxRedirects: 0 })).status()).toBe(302);
    await page.goto('/dashboard');
    const account = page.locator('[data-account-menu]');
    await expect(account).toContainText(longName);
    const trigger = account.locator('summary');
    await trigger.focus();
    await page.keyboard.press('Enter');
    await expect(account).toHaveAttribute('open', '');
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
    await account.getByRole('link', { name: 'Profil', exact: true }).focus();
    await page.keyboard.press('Escape');
    await expect(account).not.toHaveAttribute('open', '');
    await expect(trigger).toBeFocused();
    await trigger.click();
    await account.getByRole('link', { name: 'Profil', exact: true }).click();
    await expect(page).toHaveURL(/\/profile$/);
    await trigger.click();
    const request = page.waitForRequest(request => new URL(request.url()).pathname === '/logout');
    await account.getByRole('button', { name: 'Kijelentkezés', exact: true }).click();
    expect((await request).method()).toBe('POST');
    await expect(page).not.toHaveURL(/\/profile$/);
    await page.goto('/dashboard');
    await expect(page).toHaveURL(/\/login$/);
});

test('navigation and account links remain usable without JavaScript', async ({ browser }) => {
    const context = await browser.newContext({ javaScriptEnabled: false, viewport: { width: 320, height: 740 } });
    try {
        const page = await context.newPage();
        await login(page, 'browser@example.test');
        await expect(page.locator('[data-navigation-toggle]')).toBeHidden();
        await page.locator('noscript details > summary').click();
        const fallback = page.getByRole('navigation', { name: 'Főmenü JavaScript nélkül', exact: true });
        await fallback.getByRole('link', { name: 'Termékek', exact: true }).click();
        await expect(page).toHaveURL(/\/products$/);
        const account = page.locator('[data-account-menu]');
        await account.locator('summary').click();
        await account.getByRole('link', { name: 'Profil', exact: true }).click();
        await expect(page).toHaveURL(/\/profile$/);
        await page.setViewportSize({ width: 1440, height: 900 });
        await expect(page.getByRole('navigation', { name: 'Főmenü', exact: true })).toBeVisible();
        await page.getByRole('navigation', { name: 'Főmenü', exact: true }).getByRole('link', { name: 'Vezérlőpult', exact: true }).click();
        await expect(page).toHaveURL(/\/dashboard$/);
    } finally { await context.close(); }
});

test('storage restrictions do not break navigation and the skip link reaches the main content', async ({ page }) => {
    await page.addInitScript(() => {
        Storage.prototype.getItem = () => { throw new DOMException('Unavailable', 'SecurityError'); };
        Storage.prototype.setItem = () => { throw new DOMException('Unavailable', 'SecurityError'); };
    });
    await page.setViewportSize({ width: 1440, height: 900 });
    await login(page);
    const trigger = page.locator('[data-navigation-toggle]');
    await page.getByRole('button', { name: 'Oldalsáv elrejtése', exact: true }).click();
    await expect(trigger).toHaveAttribute('aria-expanded', 'false');
    await trigger.click();
    await expect(trigger).toHaveAttribute('aria-expanded', 'true');
    const skip = page.getByRole('link', { name: 'Ugrás a tartalomra', exact: true });
    await skip.focus();
    const box = await skip.boundingBox();
    expect(box.width).toBeGreaterThan(100);
    await page.keyboard.press('Enter');
    await expect(page.locator('#main-content')).toBeFocused();
});

test('menu toggles and restored pages retain unsaved form data and rapid reopen keeps the background locked', async ({ page }) => {
    await page.setViewportSize({ width: 375, height: 740 });
    await login(page, 'browser@example.test');
    await page.goto('/products/create?draft=manual');
    const input = page.locator('[name="product_name"]');
    await input.fill('El nem mentett termék');
    let navigations = 0;
    page.on('request', request => { if (request.isNavigationRequest() && request.frame() === page.mainFrame()) navigations++; });
    const dialog = page.getByRole('dialog', { name: 'Készletkezelő', exact: true });
    await page.locator('[data-navigation-toggle]').click();
    await page.evaluate(() => {
        const shell = window.Alpine.$data(document.querySelector('.app-shell'));
        shell.closeNavigation();
        shell.toggleNavigation();
    });
    await expect(dialog).toBeVisible();
    await expect(page.locator('body')).toHaveCSS('overflow', 'hidden');
    await page.evaluate(() => window.dispatchEvent(new PageTransitionEvent('pageshow', { persisted: true })));
    await expect(dialog).toBeHidden();
    await expect(page.locator('body')).not.toHaveCSS('overflow', 'hidden');
    await expect(input).toHaveValue('El nem mentett termék');
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.getByRole('button', { name: 'Oldalsáv elrejtése', exact: true }).click();
    await expect(input).toHaveValue('El nem mentett termék');
    await expect(page).toHaveURL(url => url.pathname === '/products/create' && url.searchParams.get('draft') === 'manual');
    expect(navigations).toBe(0);
});
