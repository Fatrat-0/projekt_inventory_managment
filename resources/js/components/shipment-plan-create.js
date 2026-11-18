import intentSubmission from './transfer-intent-submission';

const budapest = new Intl.DateTimeFormat('sv-SE', {
    timeZone: 'Europe/Budapest', year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23',
});

function utcDeparture(local) {
    if (!local) return null;
    // The application uses Budapest civil time, including when opened outside Hungary.
    const time = Date.parse(`${local}:00Z`);
    const matches = [2, 1].map(hours => new Date(time - hours * 3600000))
        .filter(value => Number.isFinite(value.getTime()) && budapest.format(value).replace(' ', 'T') === local);
    return matches.length === 1 ? matches[0].toISOString().slice(0, 19) + 'Z' : null;
}

export default ({ endpoint, detailUrl }) => ({
    ...intentSubmission(result => Boolean(result?.shipment?.id)),
    reference: '',
    origin: '',
    departure: '',
    vehicle: '',
    note: '',
    submitted: false,
    pending: null,

    async save() {
        if (this.submitting || this.submitted) return;
        this.clearIntentFeedback();
        const data = this.pending ?? {
            reference: this.reference.trim(), origin_warehouse_id: Number(this.origin),
            planned_departure_at: utcDeparture(this.departure), vehicle_label: this.vehicle.trim() || null, note: this.note.trim() || null,
        };
        if (!data.reference) this.fieldErrors.reference = ['Add meg a szállítmányhivatkozást.'];
        if (!data.origin_warehouse_id) this.fieldErrors.origin_warehouse_id = ['Válassz induló raktárat.'];
        if (this.departure && !data.planned_departure_at) {
            this.fieldErrors.planned_departure_at = ['Válassz egyértelmű budapesti időpontot az óraátállás időszakán kívül.'];
        }
        if (Object.keys(this.fieldErrors).length) {
            this.submissionError = 'Ellenőrizd a megjelölt adatokat.';
            this.$nextTick(() => this.$refs.feedback.focus());
            return;
        }

        const result = await this.sendIntent(endpoint, 'POST', data);
        if (result) {
            this.submitted = true;
            window.location.assign(detailUrl.replace('__shipment__', encodeURIComponent(result.shipment.id)));
            return;
        }
        this.pending = this.submissionStatus === null || this.submissionStatus < 400 || this.submissionStatus >= 500 ? data : null;
        if (this.conflict) this.intentAttempt = null;
        this.$nextTick(() => this.$refs.feedback.focus());
    },
});
