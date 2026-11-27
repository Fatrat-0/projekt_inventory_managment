export default ({ memberships, warehouses, roles, localRoles, capabilities, initial, ...values }) => ({
    ...values,
    memberships: memberships.map((membership, key) => ({ ...membership, key })),
    nextKey: memberships.length,
    warehouseSelection: '',
    submitting: false,
    confirmed: false,
    passwordEntered: false,
    confirmationEntered: false,

    restore(event, form) {
        if (!event.persisted) return;
        this.submitting = false;
        this.confirmed = false;
        for (const name of ['password', 'password_confirmation', 'current_password']) {
            const input = form.elements.namedItem(name);
            if (input) input.value = '';
        }
        this.passwordEntered = false;
        this.confirmationEntered = false;
    },

    warehouseName(id) {
        return warehouses.find(warehouse => warehouse.value === id)?.name ?? 'Raktár #' + id;
    },

    get availableWarehouses() {
        return warehouses.map(warehouse => warehouse.value).filter(id => !this.memberships.some(row => row.warehouse_id === id));
    },

    get roleLabel() {
        return roles[this.role];
    },

    add(id) {
        id = String(id ?? '');
        if (!this.availableWarehouses.includes(id) || this.role === 'admin') return;
        // Let the shared picker acknowledge the selection before clearing it for the next row.
        this.warehouseSelection = id;
        const key = this.nextKey++;
        this.memberships.push({ warehouse_id: id, roles: [], key });
        this.$nextTick(() => {
            this.warehouseSelection = '';
            document.getElementById('local-role-' + key + '-requester')?.focus();
        });
    },

    remove(index) {
        this.memberships.splice(index, 1);
        this.$nextTick(() => document.getElementById(this.role === 'admin' ? 'account-role' : 'account-warehouse-picker')?.focus());
    },

    get dirty() {
        return this.accountName.trim() !== initial.accountName || this.email.trim() !== initial.email
            || this.changes.length > 0 || this.delivery !== initial.delivery
            || (this.delivery === 'temporary' && (this.passwordEntered || this.confirmationEntered));
    },

    get revokesAccess() {
        return this.editing && (this.changes.length > 0 || this.emailEdited);
    },

    get emailEdited() {
        return this.email.trim() !== initial.email;
    },

    get hasEmptyMembership() {
        return this.memberships.some(membership => membership.roles.length === 0);
    },

    capabilitiesFor(selected) {
        return Object.entries(capabilities).flatMap(([key, capability]) => {
            const matches = capability.roles.filter(role => selected.includes(role)).length;
            return matches ? [{ key, label: capability.label, overlaps: matches > 1 }] : [];
        });
    },

    get changes() {
        const changes = [];
        const describe = values => values.map(value => localRoles[value]).join(', ');
        if (this.role !== initial.role) {
            changes.push({ key: 'role', label: 'Globális szerepkör', text: roles[initial.role] + ' → ' + roles[this.role] });
        }
        const before = new Map(initial.memberships.map(row => [row.warehouse_id, row.roles]));
        const after = new Map(this.memberships.map(row => [row.warehouse_id, row.roles]));
        for (const id of new Set([...before.keys(), ...after.keys()])) {
            const previous = before.get(id) ?? [];
            const next = after.get(id) ?? [];
            const added = next.filter(role => !previous.includes(role));
            const removed = previous.filter(role => !next.includes(role));
            if (!added.length && !removed.length && before.has(id) === after.has(id)) continue;
            const name = this.warehouseName(id);
            if (!before.has(id)) changes.push({ key: id + '-new', label: 'Új tagság', text: name });
            if (!after.has(id)) changes.push({ key: id + '-end', label: 'Megszűnő tagság', text: name });
            if (added.length) changes.push({ key: id + '-added', label: 'Hozzáadott szerepkörök', text: name + ' · ' + describe(added) });
            if (removed.length) changes.push({ key: id + '-removed', label: 'Eltávolított szerepkörök', text: name + ' · ' + describe(removed) });
        }
        return changes;
    },

    get accessChanges() {
        if (this.role === 'admin') return initial.role !== 'admin'
            ? [{ key: 'all', text: 'Minden raktár elérhető az adminisztrátori szerepkörrel.' }] : [];
        const before = new Map(initial.memberships.map(row => [row.warehouse_id, row.roles]));
        const after = new Map(this.memberships.map(row => [row.warehouse_id, row.roles]));
        const changes = [];
        if (initial.role === 'admin') changes.push({ key: 'all', text: 'Megszűnik a minden raktárra kiterjedő adminisztrátori hozzáférés. A helyi jogokat a megmaradó tagságok adják.' });
        else if (initial.role !== this.role) changes.push({ key: 'global', text: this.roleHelp[this.role] });
        for (const id of new Set([...before.keys(), ...after.keys()])) {
            const previous = initial.role === 'admin'
                ? this.capabilitiesFor(Object.keys(localRoles)) : this.capabilitiesFor(before.get(id) ?? []);
            const next = this.capabilitiesFor(after.get(id) ?? []);
            const added = next.filter(item => !previous.some(old => old.key === item.key)).map(item => item.label);
            const removed = previous.filter(item => !next.some(current => current.key === item.key)).map(item => item.label);
            const name = this.warehouseName(id);
            if (added.length) changes.push({ key: id + '-granted', text: name + ' · Új jogosultság · ' + added.join(', ') });
            if (removed.length) changes.push({ key: id + '-revoked', text: name + ' · Megszűnő jogosultság · ' + removed.join(', ') });
        }
        return changes;
    },

    confirm(event) {
        if (this.submitting || this.hasEmptyMembership) return event.preventDefault();
        if (this.confirmed) {
            this.submitting = true;
            return;
        }
        event.preventDefault();
        this.$refs.review.showModal();
    },

    save(form) {
        this.confirmed = true;
        this.$refs.review.close();
        form.requestSubmit();
        this.confirmed = false;
    },
});
