const {test} = require('node:test');
const assert = require('node:assert/strict');
global.window = {};
require('../public/assets/js/work-group-picker.js');

function picker(canCreate = true) {
    const wire = {formGroupId: 1, formWorkGroupIds: ['2'], async createEditorWorkGroup() { this.created = true; }};
    const groups = [{id: 1, name: 'Бухгалтерская компания'}, {id: 2, name: 'Узорметалл'}, {id: 3, name: 'Стройсервис'}];
    const state = window.rdWorkGroupPicker(wire, groups, canCreate, 'Создать организацию…');
    state.$nextTick = callback => callback();
    state.$refs = {input: {focus() {}}};
    return {state, wire};
}

test('separates the primary chip and additional chips, filtering out selected organizations', async () => {
    const {state, wire} = picker();
    assert.equal(state.primary.id, 1);
    assert.deepEqual(state.selected.map(group => group.id), [2]);
    state.search('СТРОЙ');
    assert.deepEqual(state.choices.map(group => group.id), [3, -1]);
    await state.choose(3);
    assert.deepEqual(wire.formWorkGroupIds, ['2', 3]);
    assert.equal(state.open, false);
    state.remove(2);
    assert.deepEqual(state.selected.map(group => group.id), [3]);
    state.remove(1);
    assert.equal(wire.formGroupId, 1);
    wire.formGroupId = 3;
    assert.equal(state.primary.id, 3);
    assert.deepEqual(state.selected, []);
});

test('keeps creation last when nothing matches and does not replace the primary organization', async () => {
    const {state, wire} = picker();
    state.search('missing');
    assert.deepEqual(state.choices.map(group => group.id), [-1]);
    await state.choose(-1);
    assert.equal(wire.created, true);
    assert.equal(wire.formGroupId, 1);
    const {state: readonly, wire: readonlyWire} = picker(false);
    readonly.search('missing');
    assert.deepEqual(readonly.choices, []);
    readonly.move(1);
    await readonly.choose(-1);
    assert.equal(readonlyWire.created, undefined);
});

test('keeps selection unchanged when searching or closing and supports keyboard movement', () => {
    const {state, wire} = picker();
    state.show();
    state.move(-1);
    assert.equal(state.choices[state.active].id, -1);
    state.close();
    assert.deepEqual(wire.formWorkGroupIds, ['2']);
    assert.equal(wire.formGroupId, 1);
});
