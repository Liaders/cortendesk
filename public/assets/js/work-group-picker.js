/** Whole-session accounting choices; device access remains server-controlled. */
window.rdWorkGroupPicker = function (wire, groups, canCreate, createLabel) {
    return {
        open: false, query: '', active: -1, pending: false,
        get primary() { return groups.find(group => group.id === Number(wire.formGroupId)); },
        get selected() {
            const ids = wire.formWorkGroupIds.map(Number);
            return groups.filter(group => group.id !== Number(wire.formGroupId) && ids.includes(group.id));
        },
        get matches() {
            const excluded = [Number(wire.formGroupId), ...wire.formWorkGroupIds.map(Number)];
            return groups.filter(group => !excluded.includes(group.id) && group.name.toLocaleLowerCase().includes(this.query.trim().toLocaleLowerCase()));
        },
        get choices() { return [...this.matches, ...(canCreate ? [{id: -1, name: createLabel}] : [])]; },
        show() { this.open = true; this.active = this.choices.length ? 0 : -1; },
        close() { this.open = false; this.query = ''; this.active = -1; },
        search(value) { this.query = value; this.show(); this.reveal(); },
        move(direction) {
            if (!this.open) { this.show(); return; }
            if (!this.choices.length) return;
            this.active = this.active < 0 ? (direction > 0 ? 0 : this.choices.length - 1)
                : (this.active + direction + this.choices.length) % this.choices.length;
            this.reveal();
        },
        reveal() { this.$nextTick(() => this.$refs.options?.querySelectorAll('[role="option"]')[this.active]?.scrollIntoView({block: 'nearest'})); },
        remove(id) { wire.formWorkGroupIds = wire.formWorkGroupIds.filter(value => Number(value) !== id); },
        async choose(id) {
            if (this.pending) return;
            if (id === -1) {
                if (!canCreate) return;
                this.pending = true;
                try { await wire.createEditorWorkGroup(); this.close(); }
                finally { this.pending = false; }
            } else if (this.matches.some(group => group.id === id)) {
                wire.formWorkGroupIds = [...wire.formWorkGroupIds.filter(value => Number(value) !== id), id];
                this.close();
                this.$refs.input.focus();
            }
        }
    };
};
