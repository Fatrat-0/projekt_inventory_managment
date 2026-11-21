export default async function readJson(url, signal, payload) {
    const headers = { Accept: 'application/json' };
    if (payload) {
        headers['Content-Type'] = 'application/json';
        headers['X-CSRF-TOKEN'] = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
    }
    let response;
    try {
        response = await fetch(url, {
            signal, headers, credentials: 'same-origin', cache: 'no-store',
            method: payload ? 'POST' : 'GET', body: payload ? JSON.stringify(payload) : undefined,
        });
    } catch (error) {
        if (error.name === 'AbortError') throw error;
        throw new Error('A kapcsolat megszakadt. Próbáld újra.');
    }

    const data = await response.json().catch(() => null);
    if (response.ok && data && !response.redirected) return data;
    const status = response.redirected ? 401 : response.status;
    const error = new Error([401, 419].includes(status)
        ? 'A munkamenet lejárt. Jelentkezz be újra, majd nyisd meg a feladatot.'
        : data?.message || 'Az adatok betöltése sikertelen. Próbáld újra.');
    error.status = status;
    error.errors = data?.errors ?? {};
    throw error;
}
