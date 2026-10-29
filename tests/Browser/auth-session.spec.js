import { test, expect } from '@playwright/test';

test('restored login with lost session recovers safely and a fresh login succeeds', async ({ page, context }) => {
    const response = await page.goto('/login');
    expect(response.headers()['cache-control']).toContain('no-store');
    const staleToken = await page.locator('input[name="_token"]').inputValue();
    await page.getByLabel('Email', { exact: true }).fill('browser@example.test');
    await page.getByLabel('Password', { exact: true }).fill('browser-test-password');
    // Keep the old HTML, but lose the session cookie as after expiry/browser restore.
    await context.clearCookies();
    const submitted = page.waitForResponse(r => r.url().endsWith('/login') && r.request().method() === 'POST');
    await page.getByRole('button', { name: 'Log in', exact: true }).click();
    expect((await submitted).status()).toBe(303);
    await expect(page).toHaveURL(/\/login$/);
    await expect(page.getByText('A bejelentkezési űrlap lejárt.', { exact: false })).toBeVisible();
    await expect(page.getByLabel('Email', { exact: true })).toHaveValue('browser@example.test');
    await expect(page.getByLabel('Password', { exact: true })).toHaveValue('');
    expect(await page.locator('input[name="_token"]').inputValue()).not.toBe(staleToken);
    await page.getByLabel('Password', { exact: true }).fill('browser-test-password');
    await page.getByRole('button', { name: 'Log in', exact: true }).click();
    await expect(page).toHaveURL(/\/dashboard$/);
});

test('fresh login after session loss retains the requested protected page', async ({ page, context }) => {
    await page.goto('/products?search=Alpha');
    await expect(page).toHaveURL(/\/login$/);
    await page.getByLabel('Email', { exact: true }).fill('browser@example.test');
    await page.getByLabel('Password', { exact: true }).fill('browser-test-password');
    await page.getByRole('button', { name: 'Log in', exact: true }).click();
    await expect(page).toHaveURL(/\/products\?search=Alpha$/);
    await context.clearCookies();
    await page.reload();
    await expect(page).toHaveURL(/\/login$/);
    await page.getByLabel('Email', { exact: true }).fill('browser@example.test');
    await page.getByLabel('Password', { exact: true }).fill('browser-test-password');
    await page.getByRole('button', { name: 'Log in', exact: true }).click();
    await expect(page).toHaveURL(/\/products\?search=Alpha$/);
});
