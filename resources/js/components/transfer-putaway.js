import movementForm from './movement-form';
import transferPicking from './transfer-picking';

const checkLabels = {
    warehouse: 'Célraktár',
    active: 'Aktív tárhely',
    category: 'Termékkategória',
    weight_input: 'Rakománytömeg',
    pallet_kind: 'Raklapigény',
    location_weight: 'Tárhely teherbírása',
    pallet_space: 'Raklapférőhely',
    level_weight: 'Polcszint teherbírása',
    system_weight: 'Tárolórendszer teherbírása',
    dimensions_fit: 'Fizikai elférés',
};

const missingInputs = {
    weight_input: 'Teljes tömeg',
    pallet_kind: 'Raklaphelyigény',
    cargo_dimensions: 'A rakomány külső méretei',
};

const weightFormat = new Intl.NumberFormat('hu-HU', { maximumFractionDigits: 3 });
const hasPositiveIntegerDigits = value => /^[1-9]\d*$/.test(String(value));
const positiveInteger = (value, max) => hasPositiveIntegerDigits(value) && Number(value) <= max;
const maxQuantity = 2147483647;
const maxWeightGrams = 1000000000;
const validWeight = value => /^(?:\d+(?:\.\d{0,3})?|\.\d{1,3})$/.test(value)
    && Number(value) >= 0.001 && Number(value) <= maxWeightGrams / 1000;

export default ({ stock, ...initial }) => {
    const movement = movementForm(stock);
    const { init: initStock, restored: restoreStock, submit: submitTransfer } = movement;
    const initialPackaging = initial.packagings[stock.product];
    const initialBoxMode = Boolean(initialPackaging
        && positiveInteger(initial.boxCount, Math.floor(maxQuantity / initialPackaging.pieces))
        && Number(initial.boxCount) * initialPackaging.pieces === Number(stock.quantity));

    Object.defineProperties(movement, Object.getOwnPropertyDescriptors(transferPicking(initial)));
    return Object.defineProperties(movement, Object.getOwnPropertyDescriptors({
        ...initial,
        quantityDraft: initialBoxMode ? initial.boxCount : stock.quantity,
        lastValidQuantity: positiveInteger(stock.quantity, maxQuantity) ? stock.quantity : '',
        boxMode: initialBoxMode,
        confirmBoxChange: false,
        confirmedWeightBasis: null,
        stalePackaging: false,
        recommendations: [],
        putawayStatus: 'idle',
        putawayError: '',
        putawayRequest: 0,
        selectionMode: initial.cargo.to_location_id ? initial.selectionMode : null,
        recommendationsOpen: !initial.cargo.to_location_id,
        showAllRecommendations: false,
        manualOpen: false,
        sourceEdited: false,
        destinationEdited: false,

        init() {
            initStock.call(this);
            if (initial.previousWeightBasis === this.weightBasis && initial.previousEffectiveWeight === this.displayWeight) {
                this.confirmWeight();
            }
            this.$watch('weightBasis', basis => {
                if (this.confirmedWeightBasis !== basis) this.confirmedWeightBasis = null;
            });
            this.$watch('putawayKey', () => this.invalidateRecommendations());
        },

        restored(event) {
            restoreStock.call(this, event);
            if (event.persisted) this.invalidateRecommendations();
        },

        get productOptions() { return this.warehouse ? this.sourceProducts[this.warehouse] ?? [] : null; },
        get sourceOptions() {
            return this.product
                ? Object.keys(this.sourceProducts).filter(id => this.sourceProducts[id].includes(this.product))
                : null;
        },

        get filterHelp() {
            if (this.product && this.sourceOptions.length === 0) return 'Ebből a termékből jelenleg egyik raktárból sem adható ki készlet.';
            if (this.warehouse && this.productOptions.length === 0) return 'A forrásraktárból jelenleg nincs átmozgatható termék.';
            if (this.product && this.warehouse) return 'A listák az átmozgatható készlet alapján szűrnek.';
            if (this.product) return 'Csak a termékből kiadható készlettel rendelkező forrásraktárak látszanak.';
            if (this.warehouse) return 'Csak a forrásraktárból kiadható termékek látszanak.';
            return 'Kezdd a termékkel vagy a forrásraktárral. A másik lista ezután szűkül.';
        },

        get destinationOptions() { return this.warehouse ? this.warehouseItems.filter(item => item.value !== this.warehouse).map(item => item.value) : null; },
        get warehousesMatch() { return Boolean(this.warehouse && this.warehouse === this.destination); },
        get destinationErrorActive() {
            return !this.destinationEdited && !(this.sourceEdited && stock.warehouse && stock.warehouse === initial.destination);
        },

        get destinationChoices() { return this.storageLocations[this.destination] ?? null; },
        get manualChoices() { return (this.destinationChoices ?? []).map(location => ({ ...location, value: location.id })); },
        get selectedLocation() { return this.destinationChoices?.find(location => location.id === this.cargo.to_location_id); },
        get selectedAdvice() {
            if (this.selectionMode !== 'recommended') return null;
            return this.recommendations.find(item => String(item.location_id) === this.cargo.to_location_id);
        },
        get selectedCapacityEstimate() {
            const score = this.selectedAdvice?.score;
            if (!score) return '';
            const capacities = [];
            if (score.remaining_weight_grams != null) capacities.push(this.weight(score.remaining_weight_grams));
            if (score.remaining_pallet_slots != null) capacities.push(score.remaining_pallet_slots + ' raklaphely');
            return capacities.length ? 'Betárolás után várhatóan ' + capacities.join(' és ') + ' marad szabadon.' : '';
        },
        get pallet() { return this.selectedLocation?.kind === 'pallet'; },
        get palletSlots() {
            if (this.palletMode === 'non-pallet') return 0;
            return this.palletMode === 'pallet' ? this.cargo.pallet_slots || null : null;
        },
        get needsWeight() { return Boolean(this.destinationChoices?.length); },
        get packaging() { return this.packagings[this.product]; },
        get maxBoxCount() { return this.packaging ? Math.floor(maxQuantity / this.packaging.pieces) : 0; },
        get quantity() {
            const limit = this.boxMode ? this.maxBoxCount : maxQuantity;
            if (!positiveInteger(this.quantityDraft, limit)) return '';
            return this.boxMode ? String(Number(this.quantityDraft) * this.packaging.pieces) : this.quantityDraft;
        },
        get quantityValid() { return this.quantity !== ''; },
        get quantityExceedsStock() {
            return this.quantityValid && this.stock !== null && Number(this.quantity) > this.stock;
        },
        get quantityInputError() {
            if (this.quantityDraft === '' || this.quantityValid) return '';
            if (!hasPositiveIntegerDigits(this.quantityDraft)) {
                return this.boxMode ? 'Pozitív egész dobozszámot adj meg.' : 'Pozitív egész darabszámot adj meg.';
            }
            return this.boxMode ? 'Legfeljebb ' + this.maxBoxCount.toLocaleString('hu-HU') + ' dobozt adhatsz meg.'
                : 'Legfeljebb 2 147 483 647 darabot adhatsz meg.';
        },
        get summaryWeight() {
            return this.quantityValid && this.effectiveWeight ? weightFormat.format(Number(this.effectiveWeight)) + ' kg' : '–';
        },

        get packagingRevision() { return this.packaging?.revision ?? this.missingPackagingRevision; },
        get weightBasis() {
            return JSON.stringify([this.product, this.quantity, this.packagingRevision, this.palletMode, this.palletSlots]);
        },
        get calculatedBoxCount() {
            const pieces = this.packaging?.pieces;
            return pieces && this.quantityValid && Number(this.quantity) % pieces === 0 ? Number(this.quantity) / pieces : 0;
        },
        get calculatedWeight() {
            const unitGrams = this.packaging?.weightGrams;
            const boxes = this.calculatedBoxCount;
            if (!unitGrams || !boxes || boxes > Math.floor(maxWeightGrams / unitGrams)) return null;

            const grams = boxes * unitGrams;
            const kilograms = Math.floor(grams / 1000);
            const fraction = String(grams % 1000).padStart(3, '0').replace(/0+$/, '');
            return { grams, unitGrams, boxes, kg: fraction ? kilograms + '.' + fraction : String(kilograms) };
        },
        get weightCalculationIssue() {
            if (!this.packaging?.weightGrams || !this.quantityValid || this.calculatedWeight) return '';
            if (!this.calculatedBoxCount) return 'A mennyiség nem egész doboznyi. Add meg a tényleges teljes tömeget.';
            return 'A számított tömeg meghaladja az 1 000 000 kg-os határt.';
        },
        get displayWeight() {
            return this.weightMode === 'manual' ? this.manualWeight : this.calculatedWeight?.kg ?? '';
        },
        get weightNeedsConfirmation() {
            return validWeight(this.displayWeight) && this.confirmedWeightBasis !== this.weightBasis;
        },
        get effectiveWeight() {
            return !this.stalePackaging && validWeight(this.displayWeight) && !this.weightNeedsConfirmation ? this.displayWeight : '';
        },
        get weightSource() {
            return this.weightMode === 'automatic' && this.effectiveWeight ? 'calculated' : '';
        },

        editWeight(value) {
            this.weightMode = 'manual';
            this.manualWeight = value;
            this.confirmWeight();
        },

        confirmWeight() {
            this.confirmedWeightBasis = !this.stalePackaging && validWeight(this.displayWeight) ? this.weightBasis : null;
        },

        useCalculatedWeight() {
            this.weightMode = 'automatic';
            this.confirmedWeightBasis = null;
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
            this.$nextTick(() => document.getElementById('transfer-box-count')?.focus());
        },

        usePieces() {
            if (this.boxMode) {
                const pieces = this.quantity || this.lastValidQuantity;
                this.boxMode = false;
                this.editQuantity(pieces);
            }
            this.confirmBoxChange = false;
        },

        fillAvailableQuantity() {
            if (this.stock <= 0) return;
            if (this.boxMode && this.stock % this.packaging.pieces === 0) {
                this.editQuantity(String(this.stock / this.packaging.pieces));
            } else {
                this.boxMode = false;
                this.editQuantity(String(this.stock));
            }
        },

        setProduct(value) {
            if (this.product !== value) {
                this.usePieces();
                this.stalePackaging = false;
            }
            this.product = value;
            this.loadStock();
        },

        get startIssues() {
            const issues = [];
            const add = (message, field) => issues.push({ message, field });
            if (!this.product) add('Válassz terméket.', 'transfer-product');
            if (!this.warehouse) add('Válassz forrásraktárt.', 'transfer-source');
            if (!this.destination) add('Válassz célraktárt.', 'transfer-destination');
            if (this.warehousesMatch) add('Válassz a forrástól eltérő célraktárt.', 'transfer-destination');
            const quantityField = this.boxMode ? 'transfer-box-count' : 'transfer-quantity';
            if (!this.quantityValid) add(this.quantityDraft === '' ? 'Add meg a mennyiséget.' : 'Javítsd a mennyiséget.', quantityField);
            else if (this.quantityExceedsStock) {
                add('A megadott mennyiség több a kiadható készletnél.', quantityField);
            } else if (this.product && this.warehouse && this.sourceIssue) {
                add(this.sourceIssue, 'transfer-picking-title');
            }
            if (this.destinationChoices?.length === 0) add('A célraktárban nincs aktív tárhely.', 'transfer-destination');
            if (this.needsWeight && !this.selectedLocation) add('Válassz célhelyet.', 'transfer-putaway-title');
            if (this.needsWeight && this.stalePackaging) add('A csomagolás módosult. Töltsd újra az űrlapot.', 'transfer-weight');
            else if (this.needsWeight && !this.displayWeight) add('Add meg a teljes tömeget.', 'transfer-weight');
            else if (this.needsWeight && !validWeight(this.displayWeight)) {
                add('A teljes tömeg 0,001 és 1 000 000 kg között legyen, legfeljebb három tizedesjeggyel.', 'transfer-weight');
            } else if (this.needsWeight && this.weightNeedsConfirmation) {
                add('Erősítsd meg a teljes tömeget.', 'transfer-weight-confirmation');
            }
            if (this.pallet && this.palletMode !== 'pallet') {
                add('A célhelyhez válaszd a Raklapos lehetőséget.', 'transfer-pallet-mode');
            } else if (this.pallet && !positiveInteger(this.palletSlots, 1000000)) {
                add('Adj meg 1 és 1 000 000 közötti egész raklapszámot.', 'transfer-slots');
            }
            if (this.selectedLocation?.kind === 'shelf' && this.palletMode === 'pallet') {
                add('Raklapos rakományhoz válassz raklaphelyet.', 'transfer-putaway-title');
            }
            return issues;
        },

        get productName() { return this.productNames[this.product] ?? ''; },

        focusIssue(id) {
            const field = document.getElementById(id);
            field?.scrollIntoView({ block: 'center' });
            field?.focus({ preventScroll: true });
        },

        warehouseName(id) { return this.warehouseItems.find(item => item.value === id)?.name ?? '–'; },

        submit(event) {
            if (this.startIssues.length) {
                event.preventDefault();
                return;
            }
            submitTransfer.call(this, event);
        },

        setSource(value) {
            this.sourceEdited = true;
            this.warehouse = value;
            if (this.warehousesMatch) this.setDestination('');
            this.loadStock();
        },

        setDestination(value) {
            this.destinationEdited = true;
            if (this.destination === value) return;
            this.destination = value;
            this.cargo.to_location_id = '';
            this.cargo.pallet_slots = '';
            this.palletMode = 'unknown';
            this.selectionMode = null;
            this.manualOpen = false;
        },

        get canRecommend() {
            return Boolean(this.destination && !this.warehousesMatch && this.product && this.quantityValid && !this.quantityExceedsStock);
        },

        get putawayInputs() {
            return {
                to_warehouse_id: this.destination,
                product_id: this.product,
                quantity: this.quantity,
                cargo_weight_kg: this.effectiveWeight,
                cargo_weight_source: this.weightSource,
                cargo_weight_packaging: this.effectiveWeight ? this.packagingRevision : '',
                pallet_slots: this.palletSlots,
                'dimensions_cm[width]': this.dimensions.width,
                'dimensions_cm[depth]': this.dimensions.depth,
                'dimensions_cm[height]': this.dimensions.height,
            };
        },

        get putawayKey() { return JSON.stringify([this.warehouse, this.quantityExceedsStock, this.putawayInputs]); },

        invalidateRecommendations() {
            this.putawayRequest++;
            this.recommendations = [];
            this.putawayStatus = this.stalePackaging ? 'error' : 'idle';
            this.putawayError = this.stalePackaging ? 'A termék csomagolása módosult. Töltsd újra az űrlapot, és erősítsd meg a teljes tömeget.' : '';
            this.showAllRecommendations = false;
            if (this.selectionMode === 'recommended') {
                this.cargo.to_location_id = '';
                this.selectionMode = null;
            }
        },

        async loadRecommendations() {
            if (!this.canRecommend || this.putawayStatus === 'loading') return;
            this.invalidateRecommendations();
            const request = this.putawayRequest;
            const key = this.putawayKey;
            const current = () => request === this.putawayRequest && key === this.putawayKey;
            this.putawayStatus = 'loading';

            try {
                const url = new URL(this.putawayEndpoint);
                for (const [name, value] of Object.entries(this.putawayInputs)) {
                    if (value !== '' && value != null) url.searchParams.set(name, value);
                }
                const response = await fetch(url, { headers: { Accept: 'application/json' }, cache: 'no-store' });
                const data = await response.json();
                if (!current()) return;
                if (response.status === 422) {
                    if (data.errors?.cargo_weight_packaging) {
                        this.stalePackaging = true;
                        this.confirmedWeightBasis = null;
                    }
                    this.putawayError = data.message;
                    this.putawayStatus = 'error';
                    return;
                }
                if (!response.ok || !Array.isArray(data.recommendations)) throw new Error('Invalid putaway response');
                this.recommendations = data.recommendations;
                this.putawayStatus = 'ready';
            } catch {
                if (!current()) return;
                this.putawayError = 'Az ajánlások lekérése sikertelen. Próbáld újra.';
                this.putawayStatus = 'error';
            }
        },

        selectRecommendation(recommendation) {
            if (!this.recommendations.includes(recommendation)) return;
            const id = String(recommendation.location_id);
            const choices = this.storageLocations[this.destination] ?? [];
            const existing = choices.find(location => location.id === id);
            if (existing) {
                existing.kind = recommendation.kind;
            } else {
                this.storageLocations[this.destination] = [...choices, {
                    ...recommendation, id, label: recommendation.code + ' · ' + (recommendation.position_label || recommendation.name),
                }];
            }
            this.setTarget(id, 'recommended');
        },

        selectManual(id) {
            if (!id) {
                this.cargo.to_location_id = '';
                this.selectionMode = null;
                return;
            }
            if (!this.destinationChoices?.some(location => location.id === id)) return;
            this.setTarget(id, 'manual');
        },

        setTarget(id, mode) {
            this.cargo.to_location_id = id;
            this.selectionMode = mode;
            this.recommendationsOpen = false;
            this.manualOpen = false;
            this.$nextTick(() => this.$refs.selectedTarget?.focus());
        },

        openRecommendations() {
            this.manualOpen = false;
            this.recommendationsOpen = true;
        },

        toggleManual() {
            this.manualOpen = !this.manualOpen;
            if (this.manualOpen) {
                this.recommendationsOpen = false;
                this.$nextTick(() => document.getElementById('transfer-location')?.focus());
            }
        },

        editTarget() {
            if (this.selectionMode === 'recommended') {
                this.openRecommendations();
                this.$refs.targetChoice.focus();
            } else if (!this.manualOpen) this.toggleManual();
        },

        get visibleRecommendations() { return this.showAllRecommendations ? this.recommendations : this.recommendations.slice(0, 3); },

        weight(grams) { return weightFormat.format(grams / 1000) + ' kg'; },
        recommendationPosition(recommendation) {
            return recommendation.short_position_label || recommendation.position_label || recommendation.name;
        },
        shortReason(recommendation) {
            const score = recommendation.score;
            if (score.same_product) return 'Azonos termék már található itt.';
            if (score.remaining_pallet_slots != null) return 'Betárolás után ' + score.remaining_pallet_slots + ' raklaphely marad szabadon.';
            if (score.remaining_weight_grams != null) return 'Betárolás után ' + this.weight(score.remaining_weight_grams) + ' marad szabadon.';
            if (recommendation.checks.passed.includes('category')) return 'A termékkategóriához megfelelő.';
            return 'A megadott feltételek alapján javasolt.';
        },
        passedChecks(recommendation) {
            return recommendation.checks.passed.map(rule => checkLabels[rule]).filter(Boolean).join(', ');
        },
        get missingCargoInputs() {
            if (!this.recommendations.length) return '';
            return Object.entries(missingInputs)
                .filter(([rule]) => this.recommendations.every(item => Object.hasOwn(item.checks.missing, rule)))
                .map(([, label]) => label).join(', ');
        },
        onsiteChecks(recommendation) {
            const checks = recommendation.checks.missing;
            return [checks.location_dimensions, checks.remaining_physical_space].filter(Boolean);
        },
    }));
};
