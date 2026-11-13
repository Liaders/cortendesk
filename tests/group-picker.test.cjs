const {test} = require('node:test');
const assert = require('node:assert/strict');
global.window = {};
require('../public/assets/js/group-picker.js');

function picker(canCreate = true) {
    const wire = {formGroupId: 2, async selectEditorGroup(id) { this.formGroupId = id; }};
    const groups = [{id: 1, name: 'Узорметалл'}, {id: 2, name: 'ACME'},
        ...Array.from({length: 500}, (_, index) => ({id: index + 3, name: `Customer ${index}`}))];
    const state = window.rdGroupPicker(wire, groups, 'Без группы', 'Новая группа', canCreate);
    state.$nextTick = callback => callback();
    state.$refs = {};
    return {state, wire};
}

test('filters hundreds of groups by a case-insensitive substring, including Cyrillic', () => {
    const {state} = picker();
    state.show();
    assert.equal(state.label, 'ACME');
    assert.equal(state.choices.length, 504);
    state.search('  МЕТАЛЛ  ');
    assert.deepEqual(state.matches.map(group => group.id), [1]);
    assert.equal(state.choices[state.active].id, 1);
    state.search('acme');
    assert.deepEqual(state.matches.map(group => group.id), [2]);
});

test('keeps special actions available and does not select a group just by typing', async () => {
    const {state, wire} = picker();
    state.show();
    state.search('missing');
    assert.equal(state.active, -1);
    assert.deepEqual(state.choices.map(group => group.id), [0, -1]);
    state.close();
    assert.equal(wire.formGroupId, 2);
    assert.equal(state.label, 'ACME');
    state.show();
    state.search('металл');
    await state.choose(state.choices[state.active].id);
    assert.equal(wire.formGroupId, 1);
    assert.equal(state.label, 'Узорметалл');
    assert.equal(state.open, false);
});

test('supports keyboard wrapping and hides creation without permission', () => {
    const {state} = picker(false);
    state.show();
    assert.equal(state.choices.some(group => group.id === -1), false);
    state.active = 0;
    state.move(-1);
    assert.equal(state.active, state.choices.length - 1);
    state.move(1);
    assert.equal(state.active, 0);
});

test('prevents duplicate selections while the server is processing a request', async () => {
    const {state, wire} = picker();
    let resolve;
    let calls = 0;
    wire.selectEditorGroup = () => { calls++; return new Promise(done => { resolve = done; }); };
    state.show();
    const first = state.choose(-1);
    await state.choose(-1);
    assert.equal(calls, 1);
    assert.equal(state.pending, true);
    resolve();
    await first;
    assert.equal(state.pending, false);
});
