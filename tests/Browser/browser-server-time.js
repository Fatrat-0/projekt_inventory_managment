export async function setBrowserServerTime(page, utc) {
    await page.context().addCookies([{
        name: 'inventory_browser_test_time',
        value: utc,
        url: new URL('/', page.url()).href,
    }]);
}
