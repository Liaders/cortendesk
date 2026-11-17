const {test} = require('node:test');
const assert = require('node:assert/strict');
global.window = {};
require('../public/assets/js/group-picker.js');
require('../public/assets/js/work-group-picker.js');

function remote(wire) {
    const state = window.rdGroupPicker(wire, [{id:1,name:'Selected',inn:'7707083893'}], 'None', 'Create', true);
    state.$nextTick = callback => callback();
    state.$refs = {};
    return state;
}

test('ignores late remote responses and keeps the selected label after search', async () => {
    const pending = [];
    const state = remote({formGroupId:1, searchEditorGroups(query,page) { return new Promise(resolve => pending.push({query,page,resolve})); }});
    const first = state.fetchGroups('old');
    const second = state.fetchGroups('new');
    pending[1].resolve({data:[{id:3,name:'New'}],has_more:false});
    await second;
    pending[0].resolve({data:[{id:2,name:'Old'}],has_more:true});
    await first;
    assert.deepEqual(state.resultGroups.map(group => group.id),[3]);
    assert.equal(state.label,'Selected');
});

test('loads further server pages and searches INN without selecting by typing', async () => {
    const calls = [];
    const wire = {formGroupId:1, async searchEditorGroups(query,page) { calls.push([query,page]); return {data:[{id:page+1,name:'Customer '+page,inn:'500100732259'}],has_more:page===1}; }};
    const state = remote(wire);
    state.query='500100';
    await state.fetchGroups(state.query);
    assert.equal(state.choices[state.active].id,2);
    await state.loadMore();
    assert.deepEqual(calls,[['500100',1],['500100',2]]);
    assert.equal(state.matches.length,2);
    assert.equal(wire.formGroupId,1);
    assert.equal(state.hasMore,false);
});

test('shows failures without presenting stale remote options and cancels closed searches', async () => {
    let resolve;
    const state = remote({formGroupId:1, searchEditorGroups() { return new Promise(done => {resolve=done;}); }});
    const request = state.fetchGroups('test');
    state.close();
    resolve({data:[{id:2,name:'Late'}],has_more:false});
    await request;
    assert.equal(state.catalog.some(group=>group.id===2),false);
    const failed = remote({formGroupId:1, async searchEditorGroups() {throw new Error('Forbidden');}});
    await failed.fetchGroups('test');
    assert.equal(failed.remoteFailed,true);
    assert.deepEqual(failed.resultGroups,[]);
    assert.equal(failed.label,'Selected');
});
