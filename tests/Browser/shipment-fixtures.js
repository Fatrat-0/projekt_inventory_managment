import { expect } from '@playwright/test';
import { randomUUID } from 'node:crypto';

export async function shipmentCommand(page, method, url, data, status) {
    const response = await page.request.fetch(url, {
        method, data, maxRedirects: 0,
        headers: {
            Accept: 'application/json',
            'X-CSRF-TOKEN': await page.locator('meta[name="csrf-token"]').getAttribute('content'),
        },
    });
    expect(response.status(), `${method} ${url}\n${await response.text()}`).toBe(status);
    return response;
}

export async function plan(page, reference, origin = 1, intentIds = []) {
    const response = await shipmentCommand(page, 'POST', '/shipment-plans', {
        operation_key: randomUUID(), origin_warehouse_id: origin, reference, intent_ids: intentIds,
    }, 201);
    return (await response.json()).shipment;
}

export async function legacyDraft(page, reference, origin, stops, extra = {}) {
    const response = await shipmentCommand(page, 'POST', '/shipments', { reference, origin_warehouse_id: origin, stops, ...extra }, 302);
    return new URL(response.headers().location, page.url()).pathname;
}
