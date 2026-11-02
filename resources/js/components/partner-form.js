export default (initial) => {
    let timer = null;
    let controller = null;
    let generation = 0;
    return {
        submitting: false,
        matches: initial.matches ?? [], total: initial.total ?? 0, token: initial.token ?? '',
        acknowledged: initial.acknowledged ?? false,
        loading: false, failed: false,
        changed() {
            clearTimeout(timer);
            controller?.abort();
            generation++;
            this.acknowledged = false;
            this.matches = []; this.total = 0; this.token = '';
            this.failed = false; this.loading = true;
            timer = setTimeout(() => this.check(), 350);
        },
        async check() {
            clearTimeout(timer);
            controller?.abort();
            const current = ++generation;
            controller = new AbortController();
            this.loading = true; this.failed = false;
            const url = new URL(initial.url);
            url.searchParams.set('name', this.$refs.form.elements.namedItem('name').value);
            url.searchParams.set('email', this.$refs.form.elements.namedItem('email').value);
            try {
                const response = await fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' }, cache: 'no-store', signal: controller.signal });
                if (!response.ok) throw new Error('Duplicate lookup failed');
                const data = await response.json();
                if (current !== generation) return;
                this.matches = data.matches; this.total = data.total; this.token = data.token;
            } catch (error) {
                if (current !== generation || error.name === 'AbortError') return;
                this.failed = true;
            } finally { if (current === generation) this.loading = false; }
        },
        submit(event) {
            if (this.submitting) { event.preventDefault(); return; }
            this.submitting = true;
            clearTimeout(timer); controller?.abort(); generation++;
        },
        restored(event) {
            if (!event.persisted) return;
            this.submitting = false;
            this.changed();
        },
        destroy() { clearTimeout(timer); controller?.abort(); generation++; },
    };
};
