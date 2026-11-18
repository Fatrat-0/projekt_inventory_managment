const maxWeightGrams = 1_000_000_000;
const maxPalletSlots = 2_147_483_647;
const decimalFormat = new Intl.NumberFormat('hu-HU', { maximumFractionDigits: 3, useGrouping: false });
const dimensionKeys = ['width', 'depth', 'height'];

// Separate digits before scaling so a value such as 5,501 never passes through a floating-point multiplication.
function scaledInput(value, decimalPlaces, maximum) {
    const input = value.trim();
    if (!input) return null;
    const match = input.match(/^(\d+|\d{1,3}(?:[ \u00a0\u202f]\d{3})+)(?:[,.](\d+))?$/);
    if (!match || (match[2]?.length ?? 0) > decimalPlaces) return undefined;

    const whole = match[1].replace(/\s/gu, '');
    const fraction = (match[2] ?? '').padEnd(decimalPlaces, '0');
    const scaled = Number(whole + fraction);
    return Number.isSafeInteger(scaled) && scaled <= maximum ? scaled : undefined;
}

const gramsInput = value => scaledInput(value, 3, maxWeightGrams);
const gramsForEdit = value => value == null ? '' : decimalFormat.format(value / 1000);
const valueForEdit = value => value == null ? '' : decimalFormat.format(value);
const blankDimensions = () => ({ width: '', depth: '', height: '' });

export default urls => ({
    itemCargoTarget: null,
    itemCargoForm: { source: 'manual', weight: '', dimensions: blankDimensions(), packagingNote: '', reason: '' },
    totalCargoForm: { weight: '', slots: '', sharedMaterials: '', reason: '' },
    itemCargoErrors: {},
    totalCargoErrors: {},

    cargoIntent(item) { return this.assignments.find(row => row.id === item?.assignment_id)?.intent; },
    cargoItemStatus(item) {
        return { confirmed: 'Megerősítve', draft: 'Tervezet', stale: 'Új megerősítés szükséges' }[item.cargo?.status] ?? 'Hiányzik';
    },
    cargoSource(source) { return source === 'calculated' ? 'Csomagolásból számított' : 'Kézzel mért'; },
    cargoActor(id, name) { return name || (id != null ? `Felhasználó #${id}` : '—'); },
    cargoDimensions(dimensions) {
        return dimensions ? `${dimensionKeys.map(key => decimalFormat.format(dimensions[key])).join(' × ')} cm` : '';
    },
    cargoSuggestion(item) {
        const suggestion = item?.suggestion;
        if (!suggestion) return '';

        return `Számított javaslat: ${suggestion.package_count} × ${this.formatMass(suggestion.gross_weight_per_package_grams)} = ${this.formatMass(suggestion.gross_weight_grams)}.`;
    },

    itemCargoReasonRequired() {
        if (this.itemCargoForm.source !== 'manual') return false;
        const weight = gramsInput(this.itemCargoForm.weight);
        const suggested = this.itemCargoTarget?.suggestion?.gross_weight_grams;
        return weight != null && suggested != null && weight !== suggested;
    },
    totalCargoReasonRequired() {
        if (!this.cargo?.all_items_confirmed) return false;
        const weight = gramsInput(this.totalCargoForm.weight);
        const shared = gramsInput(this.totalCargoForm.sharedMaterials);
        return weight != null && shared !== undefined && weight > this.cargo.confirmed_item_weight_grams + (shared ?? 0);
    },

    openItemCargo(assignmentId) {
        if (!this.mayChange || !this.cargo?.can_manage) return;
        const item = this.cargo.items.find(candidate => candidate.assignment_id === assignmentId);
        if (!item) return;

        this.itemCargoTarget = item;
        this.itemCargoErrors = {};
        const saved = item.cargo;
        this.itemCargoForm = {
            source: saved?.source !== 'manual' && item.suggestion ? 'calculated' : 'manual',
            weight: saved?.source === 'manual' ? gramsForEdit(saved.gross_weight_grams) : '',
            dimensions: saved?.dimensions_cm
                ? Object.fromEntries(dimensionKeys.map(key => [key, valueForEdit(saved.dimensions_cm[key])])) : blankDimensions(),
            packagingNote: saved?.packaging_note ?? '',
            reason: '',
        };
        this.$refs.itemCargoDimensions.open = Boolean(saved?.dimensions_cm || saved?.packaging_note);
        this.openDialog(this.$refs.itemCargoDialog, this.$refs.itemCargoDialogHeading);
    },

    async saveItemCargo(confirmed) {
        if (!this.mayChange || !this.cargo?.can_manage || !this.itemCargoTarget) return;
        const form = this.itemCargoForm;
        const item = this.itemCargoTarget;
        const errors = {};
        const weight = form.source === 'manual' ? gramsInput(form.weight) : null;
        if (form.source === 'manual' && (weight === undefined || weight === 0 || (confirmed && weight === null))) {
            errors.gross_weight_grams = 'Adj meg pozitív tömeget kg-ban, legfeljebb három tizedesjeggyel.';
        }
        if (form.source === 'calculated' && !item.suggestion) errors.source = 'Ehhez a tételhez kézzel mért tömeg szükséges.';
        if (this.itemCargoReasonRequired() && !form.reason.trim()) errors.reason = 'Add meg, miért tér el a mért tömeg a javaslattól.';

        const dimensions = Object.fromEntries(dimensionKeys.map(key => [key, scaledInput(form.dimensions[key], 2, 1_000_000)]));
        const hasDimensions = Object.values(dimensions).some(value => value !== null);
        if (hasDimensions && Object.values(dimensions).some(value => value == null || value < 1)) {
            errors.dimensions_cm = 'A méretekhez mindhárom pozitív értéket add meg cm-ben, legfeljebb két tizedesjeggyel.';
        }
        this.itemCargoErrors = errors;
        if (Object.keys(errors).length) return;

        const data = {
            source: form.source,
            confirmed,
            dimensions_cm: hasDimensions ? Object.fromEntries(dimensionKeys.map(key => [key, (dimensions[key] / 100).toFixed(2)])) : null,
            packaging_note: form.packagingNote.trim() || null,
            reason: form.reason.trim() || null,
        };
        if (form.source === 'manual') data.gross_weight_grams = weight;
        else data.packaging_revision = item.suggestion.packaging_revision;

        const url = urls.cargoItem.replace('__assignment__', encodeURIComponent(item.assignment_id));
        await this.saveCommand('saveItem', url, 'PATCH', data, 'itemCargoDialog');
    },

    openTotalCargo() {
        if (!this.mayChange || !this.cargo?.can_manage || !this.cargo.items.length) return;
        this.totalCargoErrors = {};
        const saved = this.cargo.confirmation;
        this.totalCargoForm = {
            weight: gramsForEdit(saved?.gross_weight_grams),
            sharedMaterials: gramsForEdit(saved?.shared_materials_weight_grams),
            slots: saved?.pallet_slots == null ? '' : String(saved.pallet_slots),
            reason: saved?.reason ?? '',
        };
        this.openDialog(this.$refs.totalCargoDialog, this.$refs.totalCargoDialogHeading);
    },

    async confirmTotalCargo() {
        if (!this.mayChange || !this.cargo?.can_manage || !this.cargo.items.length) return;
        const form = this.totalCargoForm;
        const errors = {};
        const weight = gramsInput(form.weight);
        const shared = gramsInput(form.sharedMaterials);
        const slots = scaledInput(form.slots, 0, maxPalletSlots);
        if (weight == null || weight === 0) errors.gross_weight_grams = 'Adj meg pozitív teljes tömeget kg-ban, legfeljebb három tizedesjeggyel.';
        if (shared === undefined) errors.shared_materials_weight_grams = 'A segédanyagok tömegét kg-ban, legfeljebb három tizedesjeggyel add meg.';
        if (slots == null) errors.pallet_slots = 'Adj meg egész számot. Ha a rakomány nem foglal raklaphelyet, írj 0-t.';
        if (this.totalCargoReasonRequired() && !form.reason.trim()) errors.reason = 'Indokold meg az eltérést az igazolt tételek és a közös anyagok tömegéhez képest.';
        this.totalCargoErrors = errors;
        if (Object.keys(errors).length) return;

        await this.saveCommand('confirmCargo', urls.cargo, 'PATCH', {
            basis_revision: this.cargo.basis_revision,
            gross_weight_grams: weight,
            pallet_slots: slots,
            shared_materials_weight_grams: shared,
            reason: form.reason.trim() || null,
        }, 'totalCargoDialog');
    },
});
