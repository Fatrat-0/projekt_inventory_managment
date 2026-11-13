function operationKey() {
    if (globalThis.crypto.randomUUID) return globalThis.crypto.randomUUID();

    const bytes = globalThis.crypto.getRandomValues(new Uint8Array(16));
    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;
    const hex = [...bytes].map(byte => byte.toString(16).padStart(2, '0')).join('');
    return [hex.slice(0, 8), hex.slice(8, 12), hex.slice(12, 16), hex.slice(16, 20), hex.slice(20)].join('-');
}

export default () => ({
    submitting: false,
    submissionError: '',
    fieldErrors: {},
    conflict: false,
    intentAttempt: null,

    clearIntentFeedback() {
        this.submissionError = '';
        this.fieldErrors = {};
        this.conflict = false;
    },

    resetIntentSubmission() {
        this.clearIntentFeedback();
        this.intentAttempt = null;
    },

    async sendIntent(url, method, payload) {
        if (this.submitting) return null;
        this.submitting = true;
        this.clearIntentFeedback();

        try {
            const signature = JSON.stringify([url, method, payload]);
            if (this.intentAttempt?.signature !== signature) {
                this.intentAttempt = { signature, key: operationKey() };
            }

            const response = await fetch(url, {
                method,
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                },
                body: JSON.stringify({ ...payload, operation_key: this.intentAttempt.key }),
            });
            const result = await response.json().catch(() => null);
            if (response.ok && result?.intent?.id) return result;

            if (response.status === 422 && result?.errors) {
                this.fieldErrors = result.errors;
                this.submissionError = 'Ellenőrizd a megjelölt adatokat.';
            } else if (response.status === 409) {
                this.conflict = true;
                this.submissionError = result?.message || 'A művelet ütközött egy korábbi módosítással.';
            } else if (response.status === 401 || response.status === 419 || response.redirected) {
                this.submissionError = 'A munkamenet lejárt. Jelentkezz be újra, majd próbáld meg ismét.';
            } else if (response.status === 403) {
                this.submissionError = result?.message || 'Ehhez a művelethez nincs jogosultságod.';
            } else if (response.status === 404) {
                this.submissionError = 'A keresett adat már nem érhető el.';
            } else {
                this.submissionError = 'A művelet eredménye nem ellenőrizhető. Próbáld meg ismét ugyanazokkal az adatokkal.';
            }
        } catch {
            this.submissionError = 'A kapcsolat megszakadt. Próbáld meg ismét ugyanazokkal az adatokkal.';
        } finally {
            this.submitting = false;
        }

        return null;
    },
});
