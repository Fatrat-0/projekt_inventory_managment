import { defineConfig } from '@playwright/test';

export default defineConfig({
    testDir: './tests/Browser',
    testMatch: '**/*.spec.js',
    workers: 1,
    fullyParallel: false,
    reporter: 'list',
    use: { baseURL: 'http://127.0.0.1:8019', channel: 'chrome', headless: true, trace: 'retain-on-failure' },
});
