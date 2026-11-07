export default () => ({
    desktop: false,
    desktopCollapsed: false,
    mobileOpen: false,
    media: null,
    mediaListener: null,
    previousOverflow: null,
    topbarObserver: null,
    init() {
        try { this.desktopCollapsed = localStorage.getItem('inventory.sidebar.collapsed') === 'true'; } catch {}
        this.media = window.matchMedia('(min-width: 1280px)');
        this.desktop = this.media.matches;
        this.mediaListener = event => {
            const fromDialog = this.$refs.navigationDialog.contains(document.activeElement);
            const fromTrigger = document.activeElement === this.$refs.navigationTrigger;
            this.desktop = event.matches;
            if (this.desktop) {
                this.closeNavigation();
                if (!this.desktopCollapsed && (fromDialog || fromTrigger)) this.$nextTick(() => this.$refs.sidebarCollapse.focus({ preventScroll: true }));
            }
            else if (this.$refs.desktopNavigation.contains(document.activeElement) || document.activeElement === document.body) {
                // CSS can hide the old focused item before the media-query event is delivered.
                this.$nextTick(() => this.$refs.navigationTrigger.focus({ preventScroll: true }));
            }
        };
        this.media.addEventListener('change', this.mediaListener);
        const topbar = this.$root.querySelector('[data-app-topbar]');
        this.topbarObserver = new ResizeObserver(() => this.$root.style.setProperty('--app-topbar-height', Math.ceil(topbar.getBoundingClientRect().height) + 'px'));
        this.topbarObserver.observe(topbar);
    },
    toggleNavigation() {
        this.closeAccount(false);
        this.closeTasks();
        if (this.desktop) {
            this.desktopCollapsed = !this.desktopCollapsed;
            try { localStorage.setItem('inventory.sidebar.collapsed', String(this.desktopCollapsed)); } catch {}
            this.$nextTick(() => (this.desktopCollapsed ? this.$refs.navigationTrigger : this.$refs.sidebarCollapse).focus({ preventScroll: true }));
            return;
        }
        if (this.mobileOpen) return this.closeNavigation();
        this.$refs.navigationDialog.showModal();
        this.mobileOpen = true;
        this.previousOverflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
    },
    closeNavigation() {
        if (this.$refs.navigationDialog.open) this.$refs.navigationDialog.close();
        this.onNavigationClosed();
    },
    onNavigationClosed() {
        if (this.$refs.navigationDialog?.open) return;
        this.mobileOpen = false;
        if (this.previousOverflow !== null) {
            document.body.style.overflow = this.previousOverflow;
            this.previousOverflow = null;
        }
    },
    closeOnBackdrop(event) {
        if (event.target !== this.$refs.navigationDialog) return;
        const rect = event.target.getBoundingClientRect();
        if (event.clientX < rect.left || event.clientX > rect.right || event.clientY < rect.top || event.clientY > rect.bottom) this.closeNavigation();
    },
    trapNavigationFocus(event) {
        const targets = Array.from(this.$refs.navigationDialog.querySelectorAll('a[href], button:not([disabled])')).filter(element => element.getClientRects().length);
        const first = targets[0];
        const last = targets[targets.length - 1];
        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    },
    closeAccount(restoreFocus = true) {
        const menu = this.$refs.accountMenu;
        if (!menu?.open) return;
        menu.open = false;
        if (restoreFocus) menu.querySelector('summary').focus();
    },
    closeTasks() {
        const menu = this.$root.querySelector('#app-tasks-menu');
        if (menu?.matches(':popover-open')) menu.hidePopover();
    },
    restorePage() {
        this.closeNavigation();
        this.closeAccount(false);
        this.closeTasks();
    },
    destroy() {
        this.media?.removeEventListener('change', this.mediaListener);
        this.topbarObserver?.disconnect();
        this.closeNavigation();
    },
});
