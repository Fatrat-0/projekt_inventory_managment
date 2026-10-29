export default (initial) => {
    let reloadTimer = null;
    return {
        open: false,
        settingsOpen: true,
        frameUrl: '',
        options: { ...initial.options },
        get downloadUrl() {
            const url = new URL(initial.url);
            url.searchParams.delete('preview');
            for (const [key, value] of Object.entries(this.options)) url.searchParams.set(key, typeof value === 'boolean' ? (value ? '1' : '0') : value);
            return url.toString();
        },
        get previewUrl() {
            const url = new URL(this.downloadUrl);
            url.searchParams.set('preview', '1');
            return url.toString();
        },
        updateOptions() {
            for (const [key, trigger] of Object.entries(initial.forceWhen ?? {})) {
                if (this.options[trigger]) this.options[key] = true;
            }
            clearTimeout(reloadTimer);
            reloadTimer = setTimeout(() => {
                if (this.open) this.frameUrl = this.previewUrl + '#zoom=page-width';
            }, 200);
        },
        resetOptions() { this.options = { ...initial.options }; this.updateOptions(); },
        show() {
            this.$refs.dialog.showModal();
            this.frameUrl = this.previewUrl + '#zoom=page-width';
            this.open = true;
        },
        closed() {
            clearTimeout(reloadTimer);
            this.open = false;
            this.frameUrl = '';
            this.$refs.trigger.focus();
        },
    };
};
