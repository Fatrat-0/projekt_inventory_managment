import warehouseStorageEditor from './warehouse-storage-editor';

const copyPlan = ({ columns, rows, positions }) => ({ columns, rows, positions: positions.map(position => ({ ...position })) });
const clamp = (value, min, max) => Math.min(max, Math.max(min, value));
const integer = (value, min, max) => Number.isInteger(value) && value >= min && value <= max;

export default initial => {
    const systems = initial.systems.map(system => ({ ...system, id: `system-${system.id}`, systemId: system.id }));
    const locations = [...initial.locations, ...systems];
    const plan = {
        columns: initial.columns, rows: initial.rows,
        positions: locations.filter(location => location.x !== null).map(({ id, x, y, width, height }) => ({ id, x, y, width, height })),
    };

    return Object.defineProperties({
        plan, saved: copyPlan(plan), version: initial.version, locations,
        minSize: initial.minSize, maxSize: initial.maxSize,
        editing: false, saving: false, stale: false, feedback: '', serverError: '', drag: null,
        selectedId: plan.positions[0]?.id ?? initial.locations[0]?.id ?? null,
        boardWidth: 0, boardHeight: 0, observer: null, zoom: 1, tool: 'select', pan: null,
        history: [copyPlan(plan)], historyIndex: 0,

        init() {
            this.observer = new ResizeObserver(([entry]) => {
                this.boardWidth = entry.contentRect.width;
                this.boardHeight = entry.contentRect.height;
            });
            this.observer.observe(this.$refs.viewport);
            const query = new URLSearchParams(window.location.search);
            const requested = systems.find(system => String(system.systemId) === query.get('system'));
            if (requested) {
                this.selectedId = requested.id;
                if (query.get('created') === '1') {
                    this.createdSystem = requested;
                    query.delete('created');
                    window.history.replaceState(window.history.state, '', `?${query}${window.location.hash}`);
                }
            }
            if (initial.canEdit && query.get('create') === '1') this.beginWizard();
        },
        destroy() { this.observer?.disconnect(); },
        get columns() { return clamp(Number(this.plan.columns) || this.minSize, this.minSize, this.maxSize); },
        get rows() { return clamp(Number(this.plan.rows) || this.minSize, this.minSize, this.maxSize); },
        get cellSize() { return Math.max(2, Math.min(this.boardWidth / this.columns, this.boardHeight / this.rows) - 1) * this.zoom; },
        get placed() {
            const locations = new Map(this.locations.map(location => [location.id, location]));
            return this.plan.positions.map(position => ({ location: locations.get(position.id), position }));
        },
        get unplaced() {
            const ids = new Set(this.plan.positions.map(position => position.id));
            return this.locations.filter(location => !ids.has(location.id));
        },
        get selected() { return this.locations.find(location => location.id === this.selectedId); },
        get selectedPosition() { return this.plan.positions.find(position => position.id === this.selectedId); },
        get changed() { return JSON.stringify(this.plan) !== JSON.stringify(this.saved); },
        get issue() {
            if (!integer(this.plan.columns, this.minSize, this.maxSize) || !integer(this.plan.rows, this.minSize, this.maxSize)) {
                return `A rács mérete ${this.minSize} és ${this.maxSize} közötti egész szám lehet.`;
            }
            const occupied = new Map();
            for (const { location, position } of this.placed) {
                if (!this.fits(position)) return `A(z) ${location.code} tárhely mérete vagy helyzete kilóg a tervrajzból.`;
                for (const cell of this.cells(position)) {
                    if (occupied.has(cell)) return `A(z) ${location.code} és a(z) ${occupied.get(cell)} tárhely átfedi egymást.`;
                    occupied.set(cell, location.code);
                }
            }
            return '';
        },
        *cells({ x, y, width, height }) {
            for (let row = y; row < y + height; row++) {
                for (let column = x; column < x + width; column++) yield row * this.columns + column;
            }
        },
        fits({ x, y, width, height }) {
            return integer(x, 0, this.columns - 1) && integer(y, 0, this.rows - 1)
                && integer(width, 1, this.columns - x) && integer(height, 1, this.rows - y);
        },
        tileStyle({ x, y, width, height }) {
            const pixels = value => `${clamp(Number(value) || 0, 0, this.maxSize) * this.cellSize}px`;
            return { left: pixels(x), top: pixels(y), width: pixels(width || 1), height: pixels(height || 1) };
        },
        capacityTone(location) {
            if (!location.active) return 'blocked';
            if (location.free <= 0 || location.freeSlots === 0) return 'full';
            const committed = location.occupied + location.reserved;
            const weight = committed / (committed + location.free);
            const slots = location.maxSlots ? (location.occupiedSlots + location.reservedSlots) / location.maxSlots : 0;
            return Math.max(weight, slots) >= 0.8 ? 'near' : 'available';
        },
        capacityState(location) {
            return { blocked: 'Zárolt vagy kikapcsolt', full: 'Betelt', near: 'Közel telített', available: 'Van szabad kapacitás' }[this.capacityTone(location)];
        },
        capacityReason(location) {
            if (!location.active) return 'Nincs aktív tárolóhely.';
            const reasons = [];
            if (location.free <= 0) reasons.push('Elfogyott a szabad teherbírás a helyen vagy a felette lévő szinten.');
            if (location.freeSlots === 0) reasons.push('Nincs szabad raklaphely.');
            return reasons.join(' ');
        },
        kilograms(grams) { return (grams / 1000).toLocaleString('hu-HU', { maximumFractionDigits: 3 }); },
        select(id) { if (!this.systemEdit) this.selectedId = id; },
        showInspector() {
            if (window.matchMedia('(max-width: 1023px)').matches) this.$refs.inspector.scrollIntoView({ block: 'start' });
        },
        beginEdit() { this.editing = true; this.tool = 'move'; this.feedback = ''; },
        record() {
            if (JSON.stringify(this.plan) === JSON.stringify(this.history[this.historyIndex])) return;
            this.history = [...this.history.slice(0, this.historyIndex + 1), copyPlan(this.plan)].slice(-50);
            this.historyIndex = this.history.length - 1;
        },
        historyMove(delta) {
            const index = this.historyIndex + delta;
            if (!this.editing || this.drag || this.saving || !this.history[index]) return;
            this.historyIndex = index;
            this.plan = copyPlan(this.history[index]);
        },
        setZoom(value) { this.zoom = clamp(value, 1, 4); },
        panStart(event) {
            if (this.tool !== 'pan' || event.button !== 0) return;
            event.preventDefault();
            const viewport = this.$refs.viewport;
            viewport.setPointerCapture(event.pointerId);
            this.pan = { id: event.pointerId, x: event.clientX + viewport.scrollLeft, y: event.clientY + viewport.scrollTop };
        },
        panMove(event) {
            if (this.pan?.id !== event.pointerId) return;
            this.$refs.viewport.scrollTo(this.pan.x - event.clientX, this.pan.y - event.clientY);
        },
        panEnd(event) { if (this.pan?.id === event.pointerId) this.pan = null; },
        openRectangle(position) {
            if (!this.fits(position)) return false;
            const { x, y, width, height } = position;
            return !this.plan.positions.some(other => x < other.x + other.width && x + width > other.x
                && y < other.y + other.height && y + height > other.y);
        },
        firstFit(width, height) {
            const occupied = new Set(this.plan.positions.filter(position => this.fits(position)).flatMap(position => [...this.cells(position)]));
            for (let y = 0; y <= this.rows - height; y++) {
                for (let x = 0; x <= this.columns - width; x++) {
                    if ([...this.cells({ x, y, width, height })].every(cell => !occupied.has(cell))) return { x, y };
                }
            }
            return null;
        },
        add(location) {
            if (this.plan.positions.some(position => position.id === location.id)) return;
            const width = location.kind === 'pallet' ? 4 : 3;
            const height = 2;
            const spot = this.firstFit(width, height);
            if (spot) {
                this.plan.positions.push({ id: location.id, ...spot, width, height });
                this.select(location.id);
                this.record();
                this.serverError = '';
                this.$nextTick(() => this.$refs.board.querySelector(`[data-floor-tile="${location.id}"]`)?.focus());
                return;
            }
            this.serverError = 'Ehhez a tárhelyhez nincs összefüggő szabad hely a rácson. Növeld a rács méretét vagy rendezd át a tárhelyeket.';
        },
        remove(id) {
            this.plan.positions = this.plan.positions.filter(position => position.id !== id);
            this.record();
            this.serverError = '';
        },
        revealSelected() { this.reveal(this.selectedPosition); },
        reveal(position) {
            if (!position) return;
            const viewport = this.$refs.viewport;
            const x = position.x * this.cellSize;
            const y = position.y * this.cellSize;
            const left = clamp(viewport.scrollLeft, x + position.width * this.cellSize - viewport.clientWidth, x);
            const top = clamp(viewport.scrollTop, y + position.height * this.cellSize - viewport.clientHeight, y);
            viewport.scrollTo({ left, top });
        },
        rotate(position) { [position.width, position.height] = [position.height, position.width]; this.record(); },
        startDrag(event, position) {
            if (this.tool === 'pan' || this.wizard || this.systemEdit) return;
            this.select(position.id);
            if (this.tool !== 'move' || !this.editing || this.saving || this.drag || event.button !== 0 || !this.fits(position)) return;
            const board = this.$refs.board.getBoundingClientRect();
            event.currentTarget.setPointerCapture(event.pointerId);
            this.drag = { id: position.id, pointerId: event.pointerId, clientX: event.clientX - board.left, clientY: event.clientY - board.top, x: position.x, y: position.y };
        },
        moveDrag(event, position) {
            const drag = this.drag;
            if (drag?.id !== position.id || drag.pointerId !== event.pointerId) return;
            const board = this.$refs.board.getBoundingClientRect();
            this.move(position, drag.x + Math.round((event.clientX - board.left - drag.clientX) / this.cellSize),
                drag.y + Math.round((event.clientY - board.top - drag.clientY) / this.cellSize));
        },
        finishDrag(event, position, cancelled = false) {
            if (this.drag?.pointerId !== event.pointerId) return;
            if (cancelled) Object.assign(position, { x: this.drag.x, y: this.drag.y });
            this.drag = null;
            if (!cancelled) this.record();
        },
        move(position, x, y) {
            position.x = clamp(x, 0, this.columns - position.width);
            position.y = clamp(y, 0, this.rows - position.height);
        },
        moveByKey(event, position) {
            const directions = { ArrowLeft: [-1, 0], ArrowRight: [1, 0], ArrowUp: [0, -1], ArrowDown: [0, 1] };
            const direction = directions[event.key];
            if (!direction || this.tool !== 'move' || !this.editing || this.saving || this.wizard || this.systemEdit || !this.fits(position)) return;
            event.preventDefault();
            this.move(position, position.x + direction[0], position.y + direction[1]);
            this.record();
        },
        cancel() {
            this.plan = copyPlan(this.saved);
            this.editing = false;
            this.drag = null;
            this.wizard = this.systemEdit = null;
            this.history = [copyPlan(this.saved)];
            this.historyIndex = 0;
            if (!this.stale) this.serverError = '';
            this.$nextTick(() => this.$refs.edit.focus());
        },
        async save() {
            if (this.saving || this.drag || this.stale || this.issue || !this.changed) return;
            const { columns, rows, positions } = this.plan;
            const result = await this.write(initial.url, 'PUT', { columns, rows,
                positions: positions.filter(position => typeof position.id === 'number'),
                systems: positions.filter(position => typeof position.id === 'string').map(position => ({ ...position, id: Number(position.id.slice(7)) })) });
            if (!result) return;
            this.version = result.version;
            this.saved = copyPlan(this.plan);
            this.history = [copyPlan(this.saved)];
            this.historyIndex = 0;
            this.editing = false;
            this.feedback = 'A tervrajz mentve.';
            this.$nextTick(() => this.$refs.edit.focus());
        },
        async write(url, method, data) {
            if (this.saving || this.stale) return null;
            this.saving = true;
            this.serverError = '';
            this.feedback = '';
            try {
                const response = await fetch(url, {
                    method, headers: {
                        'Accept': 'application/json', 'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                    body: JSON.stringify({ ...data, version: this.version }),
                });
                const result = response.status === 204 ? {} : await response.json();
                if (!response.ok) {
                    this.stale = response.status === 409 || response.status === 419;
                    if (this.placementActive && response.status === 422) this.showWizardErrors(result.errors ?? {});
                    this.serverError = response.status === 419 ? 'A munkamenet lejárt. Töltsd újra az oldalt.'
                        : (Object.values(result.errors ?? {}).flat()[0] ?? 'A mentés sikertelen. Próbáld újra.');
                    return null;
                }
                return result;
            } catch {
                this.serverError = 'A tervrajz mentése sikertelen. Próbáld újra.';
                return null;
            } finally {
                this.saving = false;
            }
        },
    }, Object.getOwnPropertyDescriptors(warehouseStorageEditor(initial)));
};
