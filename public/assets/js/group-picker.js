/** Searchable device-group picker. The server remains responsible for authorization. */
window.rdGroupPicker = function (wire, groups, noGroup, newGroup, canCreate) {
    return {
        open: false,
        query: '',
        active: 0,
        pending: false,
        get label() {
            return groups.find(group => group.id === Number(wire.formGroupId))?.name || noGroup;
        },
        get matches() {
            const query = this.query.trim().toLocaleLowerCase();
            return groups.filter(group => group.name.toLocaleLowerCase().includes(query));
        },
        get choices() {
            return [{id: 0, name: noGroup}, ...(canCreate ? [{id: -1, name: newGroup}] : []), ...this.matches];
        },
        show() {
            if (this.open) return;
            this.open = true;
            this.query = '';
            this.active = Math.max(0, this.choices.findIndex(group => group.id === Number(wire.formGroupId)));
            this.reveal();
        },
        close() {
            this.open = false;
            this.query = '';
        },
        search(value) {
            this.query = value;
            this.active = this.matches.length ? this.choices.findIndex(group => group.id === this.matches[0].id) : -1;
            this.reveal();
        },
        move(direction) {
            if (!this.open) { this.show(); return; }
            this.active = this.active < 0
                ? (direction > 0 ? 0 : this.choices.length - 1)
                : (this.active + direction + this.choices.length) % this.choices.length;
            this.reveal();
        },
        reveal() {
            this.$nextTick(() => this.$refs.options?.querySelectorAll('[role="option"]')[this.active]?.scrollIntoView({block: 'nearest'}));
        },
        async choose(id) {
            if (this.pending) return;
            this.pending = true;
            try {
                await wire.selectEditorGroup(id);
                this.close();
            } finally {
                this.pending = false;
            }
        }
    };
};
