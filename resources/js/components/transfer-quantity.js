const hasPositiveIntegerDigits = value => /^[1-9]\d*$/.test(String(value));
export const positiveInteger = (value, max) => hasPositiveIntegerDigits(value) && Number(value) <= max;
const maxQuantity = 2147483647;

export default ({ packaging, pieces, boxCount, boxFieldId }) => {
    const initialBoxMode = Boolean(packaging
        && positiveInteger(boxCount, Math.floor(maxQuantity / packaging.pieces))
        && Number(boxCount) * packaging.pieces === Number(pieces));

    return {
        quantityDraft: initialBoxMode ? boxCount : pieces,
        lastValidQuantity: positiveInteger(pieces, maxQuantity) ? pieces : '',
        boxMode: initialBoxMode,
        confirmBoxChange: false,

        get maxBoxCount() { return this.packaging ? Math.floor(maxQuantity / this.packaging.pieces) : 0; },
        get quantity() {
            const limit = this.boxMode ? this.maxBoxCount : maxQuantity;
            if (!positiveInteger(this.quantityDraft, limit)) return '';
            return this.boxMode ? String(Number(this.quantityDraft) * this.packaging.pieces) : this.quantityDraft;
        },
        get quantityValid() { return this.quantity !== ''; },
        get quantityInputError() {
            if (this.quantityDraft === '' || this.quantityValid) return '';
            if (!hasPositiveIntegerDigits(this.quantityDraft)) {
                return this.boxMode ? 'Pozitív egész dobozszámot adj meg.' : 'Pozitív egész darabszámot adj meg.';
            }
            return this.boxMode ? 'Legfeljebb ' + this.maxBoxCount.toLocaleString('hu-HU') + ' dobozt adhatsz meg.'
                : 'Legfeljebb 2 147 483 647 darabot adhatsz meg.';
        },

        editQuantity(value) {
            this.quantityDraft = value;
            this.confirmBoxChange = false;
            if (this.quantityValid) this.lastValidQuantity = this.quantity;
        },

        useBoxes(reset = false) {
            if (!this.packaging || this.boxMode) return;
            const pieces = this.packaging.pieces;
            if (!reset && this.quantityDraft !== '' && (!this.quantityValid || Number(this.quantity) % pieces !== 0)) {
                this.confirmBoxChange = true;
                return;
            }
            const boxes = reset || !this.quantity ? '' : String(Number(this.quantity) / pieces);
            this.boxMode = true;
            this.editQuantity(boxes);
            this.$nextTick(() => document.getElementById(boxFieldId)?.focus());
        },

        usePieces() {
            if (this.boxMode) {
                const pieces = this.quantity || this.lastValidQuantity;
                this.boxMode = false;
                this.editQuantity(pieces);
            }
            this.confirmBoxChange = false;
        },
    };
};
