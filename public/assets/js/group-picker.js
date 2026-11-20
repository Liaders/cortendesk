/** Searchable device-group picker. The server remains responsible for authorization. */
window.rdOrganizationRemote = function (wire, groups) {
    let timer;
    return {
        catalog: [...groups], resultGroups: [...groups], hasMore: false, loadingResults: false, remoteFailed: false,
        remote: typeof wire.searchEditorGroups === 'function', requestVersion: 0, resultPage: 1,
        async fetchGroups(query, append = false) {
            if (!this.remote || (append && this.loadingResults)) return;
            const version = append ? this.requestVersion : ++this.requestVersion;
            const page = append ? this.resultPage + 1 : 1;
            this.loadingResults = true;
            this.remoteFailed = false;
            try {
                const response = await wire.searchEditorGroups(query, page);
                if (version !== this.requestVersion) return;
                this.resultGroups = append ? [...this.resultGroups, ...response.data] : response.data;
                this.catalog = [...new Map([...this.catalog, ...response.data].map(group => [group.id, group])).values()];
                this.hasMore = response.has_more;
                this.resultPage = page;
                if (!append) {
                    this.active = query.trim()
                        ? (this.matches.length ? this.choices.findIndex(group => group.id === this.matches[0].id) : -1)
                        : (this.choices.length ? Math.max(0, this.choices.findIndex(group => group.id === Number(wire.formGroupId))) : -1);
                }
            } catch {
                if (version === this.requestVersion) { this.remoteFailed = true; this.resultGroups = []; this.hasMore = false; }
            } finally {
                if (version === this.requestVersion) this.loadingResults = false;
            }
        },
        queueSearch(query) {
            if (!this.remote) return;
            clearTimeout(timer);
            ++this.requestVersion;
            this.loadingResults = true;
            timer = setTimeout(() => this.fetchGroups(query), 250);
        },
        loadMore() { return this.fetchGroups(this.query, true); },
        cancelSearch() { clearTimeout(timer); ++this.requestVersion; this.loadingResults = false; },
        destroy() { this.cancelSearch(); }
    };
};
window.rdGroupPicker = function (wire, groups, noGroup, newGroup, canCreate) {
    return {
        ...window.rdOrganizationRemote(wire, groups),
        open: false,
        query: '',
        active: 0,
        pending: false,
        pointerWithin: false,
        get label() {
            return wire.editorGroupLabels?.[wire.formGroupId]?.name || this.catalog.find(group => group.id === Number(wire.formGroupId))?.name || noGroup;
        },
        get matches() {
            const query = this.query.trim().toLocaleLowerCase();
            return (this.remote ? this.resultGroups : this.catalog).filter(group => group.name.toLocaleLowerCase().includes(query) || String(group.inn || '').includes(query));
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
            this.fetchGroups('');
        },
        close() {
            this.open = false;
            this.query = '';
            this.cancelSearch();
        },
        search(value) {
            this.query = value;
            this.active = this.matches.length ? this.choices.findIndex(group => group.id === this.matches[0].id) : -1;
            this.reveal();
            this.queueSearch(value);
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

/** The toolbar uses the same searchable control, with All/Unassigned actions. */
window.rdOrganizationFilter = function (wire, groups, allGroups, noGroup) {
    const adapter = {
        get formGroupId() { return wire.group; },
        get editorGroupLabels() { return wire.filterGroupLabels; },
        searchEditorGroups(query, page) { return wire.searchFilterGroups(query, page); },
        selectEditorGroup(id) { return wire.selectGroupFilter(id); }
    };
    const picker = window.rdGroupPicker(adapter, groups, allGroups, '', false);
    Object.defineProperties(picker, {
        label: {get() { return Number(wire.group) === -1 ? noGroup : adapter.editorGroupLabels?.[wire.group]?.name || this.catalog.find(group => group.id === Number(wire.group))?.name || allGroups; }},
        choices: {get() { return [{id: 0, name: allGroups}, {id: -1, name: noGroup}, ...this.matches]; }}
    });
    return picker;
};
