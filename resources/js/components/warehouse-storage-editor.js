const types = {
    rack: { name: 'Polcos állvány', widthMeters: 3, depthMeters: 1, rack_height: 2, levels: 3, bins: 4, bin_weight: 50, level_weight: 150, system_weight: 400 },
    pallet: { name: 'Raklapos állvány', widthMeters: 3, depthMeters: 2, rack_height: 4, levels: 2, bins: 2, bin_weight: 500, level_weight: 1000, system_weight: 1800 },
    floor: { name: 'Padlótároló zóna', widthMeters: 3, depthMeters: 3, rack_height: null, levels: 1, bins: 4, bin_weight: 500, level_weight: 0, system_weight: 1600 },
};

const placementKeys = { ArrowLeft: [-1, 0], ArrowRight: [1, 0], ArrowUp: [0, -1], ArrowDown: [0, 1] };
const placementStates = { positioning: 'POSITIONING', selected: 'POSITION_SELECTED', saving: 'SAVING' };
const integer = (value, max) => Number.isInteger(value) && value > 0 && value <= max;
const decimal = (value, min, max, places) => Number.isFinite(value) && value >= min && value <= max && value === Number(value.toFixed(places));
const serial = number => String(number).padStart(2, '0');
const specified = values => Object.fromEntries(Object.entries(values).filter(([, value]) => value !== '' && value != null));
const overlaps = (a, b) => a.x < b.x + b.width && a.x + a.width > b.x && a.y < b.y + b.height && a.y + a.height > b.y;

export default initial => {
    const systemCodes = new Set(initial.systems.map(system => system.code.toUpperCase()));
    const binCodes = new Set([...initial.locations, ...initial.systems.flatMap(system => system.levels.flatMap(level => level.bins))]
        .map(bin => bin.code.toUpperCase()));
    let sequence = 1;
    const usedPrefixes = new Set([...binCodes].map(code => code.match(/^(A-\d+)-\d/)?.[1]).filter(Boolean));
    while (systemCodes.has(`A-${serial(sequence)}`) || usedPrefixes.has(`A-${serial(sequence)}`)) sequence++;

    return {
        wizard: null, wizardDrafts: {}, step: 1, wizardError: '', wizardServerErrors: {}, systemEdit: null,
        placementState: null, placementHover: null, selectedPlacement: null,
        previewSelection: null, createdSystem: null, wizardReturn: null,
        storageTypes: Object.entries(types).map(([kind, type]) => ({ kind, label: type.name })),

        beginWizard(kind = 'rack') {
            this.wizardReturn = { editing: this.editing, tool: this.tool };
            this.editing = true;
            this.tool = 'select';
            this.systemEdit = null;
            this.wizardDrafts = {};
            this.chooseType(kind);
            this.step = 1;
            this.$nextTick(() => this.showInspector());
        },
        chooseType(kind) {
            if (this.wizard?.kind === kind) return;
            if (this.wizard) this.wizardDrafts[this.wizard.kind] = this.wizard;
            this.wizard = this.wizardDrafts[kind] ?? {
                ...types[kind], kind, zone: this.wizard?.zone ?? initial.zones[0] ?? 'Tárolótér',
                code: this.wizard?.code ?? `A-${serial(sequence)}`, x: 0, y: 0, rotated: false,
                placementStarted: false, bin_dimensions: {}, level_weights: {},
            };
            this.previewSelection = null;
            this.clearWizardFeedback();
        },
        cancelWizard() {
            if (this.savingPlacement) return;
            this.wizard = null;
            this.resetPlacement();
            Object.assign(this, this.wizardReturn);
            this.clearWizardFeedback();
        },
        clearWizardFeedback() { this.wizardError = ''; this.wizardServerErrors = {}; },
        get previewActive() { return !!this.wizard && (this.step === 2 || this.step === 3); },
        get placementActive() { return !!this.wizard && this.step === 4; },
        get positioning() { return this.placementActive && this.placementState === placementStates.positioning; },
        get positionSelected() { return this.placementActive && this.placementState === placementStates.selected; },
        get savingPlacement() { return this.placementActive && this.placementState === placementStates.saving; },
        get floorDraft() { return this.wizard?.kind === 'floor'; },
        get draftCode() { return this.wizard?.code.trim().toUpperCase() ?? ''; },
        get draftLevels() { return this.floorDraft ? 1 : (integer(this.wizard?.levels, 12) ? this.wizard.levels : 0); },
        get draftBins() { return integer(this.wizard?.bins, 24) ? this.wizard.bins : 0; },
        get draftBinCount() { return this.draftLevels * this.draftBins; },
        binCode(level, bin) {
            return [this.draftCode || '…', ...(this.floorDraft ? [] : [serial(level)]), serial(bin)].join('-');
        },
        get draftBinCodes() {
            return Array.from({ length: this.draftLevels }, (_, level) =>
                Array.from({ length: this.draftBins }, (_, bin) => this.binCode(level + 1, bin + 1))).flat();
        },
        get calculatedBinDimensions() {
            return {
                width_cm: this.draftBins && Math.floor(Math.round(this.wizard.widthMeters * 100) / this.draftBins),
                depth_cm: Math.round(this.wizard.depthMeters * 100),
                height_cm: this.floorDraft ? 100 : this.draftLevels && Math.floor(Math.round(this.wizard.rack_height * 100) / this.draftLevels),
            };
        },
        get effectiveBinDimensions() { return { ...this.calculatedBinDimensions, ...specified(this.wizard.bin_dimensions) }; },
        get previewDimensionLabel() {
            return Object.values(this.effectiveBinDimensions).map(value => value > 0 ? value : '–').join(' × ') + ' cm';
        },
        get levelLoads() {
            return Array.from({ length: this.draftLevels }, (_, index) => {
                const limit = specified(this.wizard.level_weights)[index + 1] ?? this.wizard.level_weight;
                const binsLimit = Math.round(Number(this.wizard.bin_weight) * 1000) * this.draftBins;
                return { number: index + 1, limit, usable: this.floorDraft ? binsLimit : Math.min(binsLimit, Math.round(Number(limit) * 1000)) };
            });
        },
        get draftCapacity() {
            if (Object.keys(this.fieldErrors(3)).length || !this.draftBinCount) return null;
            const nominal = Math.round(this.wizard.bin_weight * 1000) * this.draftBinCount;
            const levels = this.levelLoads.reduce((sum, level) => sum + level.usable, 0);
            const system = Math.round(this.wizard.system_weight * 1000);
            return { nominal, levels, system, usable: Math.min(levels, system) };
        },
        fieldErrors(step) {
            const draft = this.wizard;
            const errors = {};
            if (step === 2) {
                if (!draft.name.trim()) errors.name = 'Add meg a tárolórendszer nevét.';
                if (!draft.zone.trim()) errors.zone = 'Add meg a raktári zónát.';
                const code = this.draftCode;
                if (!/^[A-Z0-9][A-Z0-9._-]{0,23}$/.test(code) || /^T\d+$/.test(code)) errors.code = 'Adj meg érvényes azonosítót, például A-01.';
                else if (systemCodes.has(code) || this.draftBinCodes.some(bin => binCodes.has(bin))) {
                    errors.code = 'Ez az azonosító vagy az egyik belőle képzett tárhelykód már használatban van.';
                }
                const width = draft.widthMeters * 2;
                const depth = draft.depthMeters * 2;
                const max = Math.max(this.columns, this.rows);
                if (!integer(width, max)) errors.width = 'A szélesség 0,5 m-es lépésekben adható meg a raktár határain belül.';
                if (!integer(depth, max)) errors.height = 'A mélység 0,5 m-es lépésekben adható meg a raktár határain belül.';
                if (!errors.width && !errors.height && !(width <= this.columns && depth <= this.rows) && !(depth <= this.columns && width <= this.rows)) {
                    errors.height = 'Ekkora alapterület egyik tájolásban sem fér el a raktárban.';
                }
                if (!this.floorDraft && !decimal(draft.rack_height, 0.5, 30, 2)) errors.rack_height = 'Adj meg 0,5 és 30 m közötti magasságot, legfeljebb két tizedesjeggyel.';
                if (!this.draftLevels) errors.levels = '1 és 12 közötti egész számot adj meg.';
                if (!this.draftBins) errors.bins = '1 és 24 közötti egész számot adj meg.';
                const dimensions = specified(draft.bin_dimensions);
                const maxDimensions = { ...this.calculatedBinDimensions, ...(this.floorDraft ? { height_cm: 3000 } : {}) };
                for (const [key, value] of Object.entries(dimensions)) {
                    if (!integer(value, maxDimensions[key])) errors[`bin_dimensions.${key}`] = 'Egész centimétert adj meg úgy, hogy a rekeszek elférjenek.';
                }
            } else if (step === 3) {
                const limits = { bin_weight: draft.bin_weight, system_weight: draft.system_weight };
                if (!this.floorDraft) {
                    limits.level_weight = draft.level_weight;
                    for (const [number, value] of Object.entries(this.levelWeights())) limits[`level_weights.${number}`] = value;
                }
                for (const [key, value] of Object.entries(limits)) {
                    if (!decimal(value, 0.001, 1000000, 3)) errors[key] = 'Adj meg 0,001 és 1 000 000 kg közötti értéket, legfeljebb három tizedesjeggyel.';
                }
            }
            return errors;
        },
        get currentWizardErrors() { return { ...this.fieldErrors(this.step), ...this.wizardServerErrors }; },
        previousStep() {
            if (this.saving || this.savingPlacement) return;
            this.step--;
            this.resetPlacement();
            this.clearWizardFeedback();
        },
        nextStep() {
            if (Object.keys(this.currentWizardErrors).length) return;
            this.step++;
            this.clearWizardFeedback();
            if (!this.placementActive) return;
            this.tool = 'select';
            this.resetPlacement(placementStates.positioning);
            if (!this.wizard.placementStarted) {
                const { width, height } = this.placement;
                const spot = this.firstFit(width, height);
                const turnedSpot = spot ? null : this.firstFit(height, width);
                if (spot || turnedSpot) Object.assign(this.wizard, spot ?? turnedSpot);
                this.wizard.rotated = !!turnedSpot;
                this.wizard.placementStarted = true;
            }
            this.$nextTick(() => this.reveal(this.placement));
        },
        get placement() {
            const { x, y, widthMeters, depthMeters, rotated } = this.wizard;
            return { x, y, width: (rotated ? depthMeters : widthMeters) * 2, height: (rotated ? widthMeters : depthMeters) * 2 };
        },
        get shownPlacement() {
            return this.selectedPlacement ?? { ...this.placement, ...(this.tool === 'pan' ? null : this.placementHover) };
        },
        resetPlacement(state = null) {
            this.placementHover = null;
            this.selectedPlacement = null;
            this.placementState = state;
        },
        editPlacement() {
            if (!this.positionSelected || this.saving) return;
            this.resetPlacement(placementStates.positioning);
            this.tool = 'select';
            this.clearWizardFeedback();
            this.$refs.viewport.scrollIntoView({ block: 'nearest' });
            this.$refs.viewport.focus({ preventScroll: true });
        },
        escapePlacement(event) {
            if (!this.placementActive || this.savingPlacement || event.defaultPrevented) return;
            event.preventDefault();
            event.stopPropagation();
            this.previousStep();
            this.$nextTick(() => this.$refs.wizardHeading.focus());
        },
        placementIssue(position) {
            if (!this.fits(position)) return 'Az állvány kilóg a tervrajzból. Módosítsd a helyzetét vagy a tájolását.';
            if (!this.openRectangle(position)) return 'Az állvány átfed egy meglévő objektumot.';
            return '';
        },
        placementPoint(event) {
            const rect = this.$refs.board.getBoundingClientRect();
            return { x: Math.floor((event.clientX - rect.left) / this.cellSize), y: Math.floor((event.clientY - rect.top) / this.cellSize) };
        },
        hoverPlacement(event) {
            if (this.positioning && this.tool !== 'pan' && event.pointerType !== 'touch') this.placementHover = this.placementPoint(event);
        },
        choosePlacement(event) {
            if (!this.positioning || this.tool === 'pan' || event.detail === 0) return;
            this.selectPlacement({ ...this.placement, ...this.placementPoint(event) });
            this.$refs.viewport.focus({ preventScroll: true });
        },
        selectPlacement(position) {
            if (!this.positioning) return;
            const { x, y } = position;
            Object.assign(this.wizard, { x, y });
            this.placementHover = null;
            this.clearWizardFeedback();
            if (this.placementIssue(position)) return;
            this.selectedPlacement = { ...position, rotated: this.wizard.rotated };
            this.placementState = placementStates.selected;
        },
        placementOverlaps(position) {
            return this.placementActive && overlaps(this.shownPlacement, position);
        },
        placementKey(event) {
            if (!this.positioning || this.tool === 'pan' || event.altKey || event.ctrlKey || event.metaKey) return;
            const direction = placementKeys[event.key];
            if (direction) {
                event.preventDefault();
                const { x, y, width, height } = this.placement;
                this.wizard.x = Math.max(0, Math.min(this.columns - width, (Number(x) || 0) + direction[0]));
                this.wizard.y = Math.max(0, Math.min(this.rows - height, (Number(y) || 0) + direction[1]));
                this.placementHover = null;
                this.clearWizardFeedback();
                this.reveal(this.placement);
            } else if (event.key.toLowerCase() === 'r') {
                event.preventDefault();
                this.rotatePlacement();
            } else if (event.key === 'Enter') {
                event.preventDefault();
                this.selectPlacement(this.shownPlacement);
            }
        },
        rotatePlacement() {
            if (!this.positioning) return;
            this.wizard.rotated = !this.wizard.rotated;
            this.placementHover = null;
            this.clearWizardFeedback();
            this.reveal(this.placement);
        },
        levelWeights() {
            return Object.fromEntries(Object.entries(specified(this.wizard.level_weights)).filter(([number]) => Number(number) <= this.draftLevels));
        },
        showWizardErrors(errors) {
            const first = Object.keys(errors)[0];
            if (!first) return;
            const field = first.split('.')[0];
            if (['name', 'zone', 'code', 'width', 'height', 'rack_height', 'levels', 'bins', 'bin_dimensions'].includes(field)) this.step = 2;
            if (['bin_weight', 'level_weight', 'level_weights', 'system_weight'].includes(field)) this.step = 3;
            this.wizardServerErrors = Object.fromEntries(Object.entries(errors).map(([key, messages]) => [key, messages[0]]));
            this.$nextTick(() => this.showInspector());
        },
        get canCreateSystem() {
            return this.positionSelected && !this.saving && !this.stale && !this.changed
                && !Object.keys(this.fieldErrors(2)).length && !Object.keys(this.fieldErrors(3)).length
                && !Object.keys(this.wizardServerErrors).length && !this.placementIssue(this.selectedPlacement);
        },
        async confirmSystem() {
            if (!this.canCreateSystem) return;
            this.placementState = placementStates.saving;
            this.clearWizardFeedback();
            const { name, zone, kind, rack_height, levels, bins, bin_weight, level_weight, system_weight, bin_dimensions } = this.wizard;
            const result = await this.write(initial.systemUrl, 'POST', {
                name, zone, kind, rack_height, levels, bins, bin_weight, level_weight, system_weight,
                ...this.selectedPlacement, code: this.draftCode, bin_dimensions: specified(bin_dimensions), level_weights: this.levelWeights(),
            });
            if (result) {
                this.openSystem(result.id, true);
                return;
            }
            const errors = this.wizardServerErrors;
            if (!this.placementActive) this.resetPlacement();
            else if (errors.positions || errors.x || errors.y) this.resetPlacement(placementStates.positioning);
            else this.placementState = placementStates.selected;
            if (!Object.keys(errors).length) this.wizardError = this.serverError;
        },
        beginSystemEdit(system) {
            this.editing = true;
            this.tool = 'select';
            this.systemEdit = { id: system.id, name: system.name, zone: system.zone,
                level_weight: null, system_weight: system.systemWeight,
                rack_height: system.rackHeight };
        },
        async saveSystemEdit() {
            if (this.changed || this.saving || !this.systemEdit) return;
            const system = this.locations.find(location => location.id === this.systemEdit.id);
            const result = await this.write(system.url, 'PATCH', this.systemEdit);
            if (result) this.openSystem(system.systemId);
        },
        openSystem(id, created = false) {
            const url = new URL(window.location.href);
            url.searchParams.delete('create');
            url.searchParams.set('system', id);
            if (created) url.searchParams.set('created', '1');
            else url.searchParams.delete('created');
            window.location.replace(url);
        },
    };
};
