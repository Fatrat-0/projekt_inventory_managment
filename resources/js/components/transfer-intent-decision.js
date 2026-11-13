import intentSubmission from './transfer-intent-submission';

const decisionDate = new Intl.DateTimeFormat('hu-HU', {
    dateStyle: 'medium',
    timeStyle: 'short',
    timeZone: 'Europe/Budapest',
});

export default ({ workflow, workflowUrl, actionUrls, statusLabels, statusColors, nextSteps, eventLabels, decisions }) => ({
    ...intentSubmission(),
    workflow,
    statusLabels,
    statusColors,
    nextSteps,
    eventLabels,
    decisions,
    drafts: { approve: '', reject: '', cancel: '' },
    activeAction: '',
    refreshing: false,
    refreshError: '',
    statusNotice: '',
    committedAction: '',
    actionTrigger: null,

    get decision() { return this.decisions[this.activeAction]; },
    get actionAvailable() { return this.workflow.actions.includes(this.activeAction); },

    init() {
        const url = new URL(window.location.href);
        if (url.searchParams.get('submitted') !== '1') return;
        url.searchParams.delete('submitted');
        window.history.replaceState(window.history.state, '', url);
    },

    openDecision(action, trigger) {
        if (!this.workflow.actions.includes(action) || this.submitting || this.refreshing) return;

        this.actionTrigger = trigger;
        this.activeAction = action;
        if (!this.conflict) this.clearIntentFeedback();
        this.statusNotice = '';
        this.$refs.decisionDialog.showModal();
        this.$nextTick(() => this.$refs.reason.focus());
    },

    closeDecision() {
        if (this.submitting || this.refreshing) return;
        this.$refs.decisionDialog.close();
    },

    decisionClosed() {
        this.activeAction = '';
        if (this.actionTrigger?.isConnected && !this.actionTrigger.disabled && this.actionTrigger.getClientRects().length) {
            this.actionTrigger.focus();
        } else {
            this.$refs.workflowHeading.focus();
        }
        this.actionTrigger = null;
    },

    async confirmDecision() {
        if (!this.actionAvailable || this.submitting || this.refreshing || this.conflict || this.committedAction) return;

        const action = this.activeAction;
        const result = await this.sendIntent(actionUrls[action], 'PATCH', {
            version: this.workflow.version,
            reason: this.drafts[action].trim() || null,
        });
        if (!result) {
            if (this.conflict) {
                this.$nextTick(() => this.$refs.refreshInDialog?.focus());
            } else if (this.fieldErrors.reason) {
                this.$nextTick(() => this.$refs.reason.focus());
            }
            return;
        }

        this.committedAction = action;
        await this.refreshWorkflow();
    },

    async refreshWorkflow() {
        if (this.refreshing || this.submitting) return;

        this.refreshing = true;
        this.refreshError = '';
        const conflict = this.conflict;
        const committed = this.committedAction;
        try {
            const response = await fetch(workflowUrl, {
                credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' },
            });
            if (response.status === 403 || response.status === 404) {
                this.workflow = { ...this.workflow, actions: [] };
                this.refreshError = 'Az igény aktuális adatai már nem érhetők el.';
                return;
            }
            if (!response.ok) throw new Error('Refresh failed');
            const { workflow: current } = await response.json();
            if (!current || !Array.isArray(current.actions) || !Array.isArray(current.events)) {
                throw new Error('Invalid workflow');
            }

            this.workflow = current;
            this.clearIntentFeedback();
            if (committed) {
                this.drafts[committed] = '';
                this.committedAction = '';
                this.resetIntentSubmission();
                this.statusNotice = this.decisions[committed].success;
                this.$refs.decisionDialog.close();
            } else if (conflict) {
                this.statusNotice = 'Az igény adatai frissültek.';
                if (this.actionAvailable) this.$nextTick(() => this.$refs.reason.focus());
            }
        } catch {
            this.refreshError = committed
                ? 'A döntés mentése sikerült. Az aktuális állapot lekéréséhez próbáld újra a frissítést.'
                : 'Az igény aktuális adatai nem tölthetők be. Próbáld újra a frissítést.';
        } finally {
            this.refreshing = false;
        }
    },

    eventDate(value) {
        const date = value ? new Date(value) : null;
        return date && Number.isFinite(date.getTime()) ? decisionDate.format(date) : '';
    },
});
