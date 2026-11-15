import intentSubmission from './transfer-intent-submission';

const decisionDate = new Intl.DateTimeFormat('hu-HU', {
    dateStyle: 'medium',
    timeStyle: 'short',
    timeZone: 'Europe/Budapest',
});
const quantityFormat = new Intl.NumberFormat('hu-HU');

export default ({ workflow, workflowUrl, actionUrls, statusLabels, statusColors, nextSteps, eventLabels, decisions, preparationLabels }) => ({
    ...intentSubmission(),
    workflow,
    statusLabels,
    statusColors,
    nextSteps,
    eventLabels,
    decisions,
    preparationLabels,
    drafts: { approve: '', reject: '', cancel: '', releaseStock: '' },
    activeAction: '',
    preparationAction: '',
    refreshing: false,
    refreshError: '',
    statusNotice: '',
    committedAction: '',
    actionTrigger: null,

    get decision() { return this.decisions[this.activeAction]; },
    get preparation() { return this.workflow.preparation; },
    get actionAvailable() { return this.canAct(this.activeAction); },
    get actionBlocked() { return this.submitting || this.refreshing || this.conflict || !!this.committedAction; },
    get preparationError() {
        if (!this.preparationAction || this.activeAction || this.conflict || this.committedAction) return '';
        return Object.values(this.fieldErrors).flat()[0] || this.submissionError;
    },

    canAct(action) {
        return this.workflow.actions.includes(action) || this.preparation?.actions.includes(action) === true;
    },

    init() {
        const url = new URL(window.location.href);
        if (url.searchParams.get('submitted') !== '1') return;
        url.searchParams.delete('submitted');
        window.history.replaceState(window.history.state, '', url);
    },

    openDecision(action, trigger) {
        if (!this.canAct(action) || this.actionBlocked) return;

        this.actionTrigger = trigger;
        this.activeAction = action;
        this.preparationAction = '';
        this.clearIntentFeedback();
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
            this.focusCurrentHeading();
        }
        this.actionTrigger = null;
    },

    async confirmDecision() {
        if (!this.actionAvailable || this.actionBlocked) return;

        await this.runAction(this.activeAction, this.drafts[this.activeAction].trim() || null);
    },

    async submitPreparation(action) {
        if (!this.canAct(action) || this.actionBlocked) return;

        this.preparationAction = action;
        this.statusNotice = '';
        await this.runAction(action, null);
    },

    async runAction(action, reason) {
        const { url, method } = actionUrls[action];
        const result = await this.sendIntent(url, method, {
            version: this.workflow.version,
            reason,
        });
        if (!result) {
            if (this.conflict) {
                this.$nextTick(() => (this.activeAction ? this.$refs.refreshInDialog : this.$refs.refreshOutsideDialog)?.focus());
            } else if (this.activeAction && this.fieldErrors.reason) {
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
                this.workflow = { ...this.workflow, actions: [], preparation: null };
                this.refreshError = 'Az igény aktuális adatai már nem érhetők el.';
                return;
            }
            if (!response.ok) throw new Error('Refresh failed');
            const { workflow: current } = await response.json();
            if (!current || !Array.isArray(current.actions) || !Array.isArray(current.events)) {
                throw new Error('Invalid workflow');
            }

            this.workflow = current;
            this.preparationAction = '';
            this.clearIntentFeedback();
            if (committed) {
                this.committedAction = '';
                this.resetIntentSubmission();
                this.statusNotice = this.decisions[committed].success;
                if (this.$refs.decisionDialog.open) {
                    this.drafts[committed] = '';
                    this.$refs.decisionDialog.close();
                } else {
                    this.$nextTick(() => this.focusCurrentHeading());
                }
            } else if (conflict) {
                this.statusNotice = 'Az igény adatai frissültek.';
                if (this.actionAvailable) this.$nextTick(() => this.$refs.reason.focus());
            }
        } catch {
            this.refreshError = committed
                ? 'A művelet mentése sikerült. Az aktuális állapot lekéréséhez próbáld újra a frissítést.'
                : 'Az igény aktuális adatai nem tölthetők be. Próbáld újra a frissítést.';
        } finally {
            this.refreshing = false;
        }
    },

    focusCurrentHeading() {
        (this.preparation ? this.$refs.preparationHeading : this.$refs.workflowHeading)?.focus();
    },

    pieceCount(value) {
        return quantityFormat.format(value) + ' db';
    },

    eventDate(value) {
        const date = value ? new Date(value) : null;
        return date && Number.isFinite(date.getTime()) ? decisionDate.format(date) : '';
    },
});
