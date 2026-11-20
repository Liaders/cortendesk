const {test} = require('node:test');
const assert = require('node:assert/strict');
global.window = {};
require('../public/assets/js/group-picker.js');
require('../public/assets/js/report-date.js');

test('organization filter retains reactive matches and selects only on explicit confirmation', async () => {
    const wire = {group:0, filterGroupLabels:{}, async searchFilterGroups() { return {data:[{id:7,name:'УЗОРМЕТАЛЛ'}],has_more:false}; }, async selectGroupFilter(id) { this.group=id; }};
    const state = window.rdOrganizationFilter(wire, [], 'All', 'Unassigned');
    state.$nextTick = callback => callback(); state.$refs = {};
    state.query = 'металл';
    await state.fetchGroups(state.query);
    assert.equal(state.matches.length,1);
    assert.equal(state.choices[state.active].id,7);
    assert.equal(wire.group,0);
    await state.choose(7);
    assert.equal(wire.group,7);
    assert.equal(state.label,'УЗОРМЕТАЛЛ');
    await state.choose(-1);
    assert.equal(state.label,'Unassigned');
    await state.choose(0);
    assert.equal(state.label,'All');
});

test('native calendar preserves human dates, submits immediately and never converts timezones', () => {
    const calls=[];
    const wire={dateFrom:'1.11.2026', $set(field,value,live) { calls.push([field,value,live]); this[field]=value; }};
    const state=window.rdReportDate(wire,'dateFrom');
    assert.equal(state.iso,'2026-11-01');
    state.select('2026-12-31');
    assert.deepEqual(calls,[['dateFrom','31.12.2026',true]]);
    assert.equal(state.iso,'2026-12-31');
    wire.dateFrom='2026-02-03'; assert.equal(state.iso,'2026-02-03');
    wire.dateFrom='invalid'; assert.equal(state.iso,'');
    state.select('invalid'); assert.equal(calls.length,1);
});

test('calendar uses native picker and falls back to a focusable date field', () => {
    const state=window.rdReportDate({},'dateFrom');
    let opened=0,focused=0,visible=0;
    state.$refs={calendar:{showPicker(){opened++;}}};
    state.openCalendar(); assert.equal(opened,1);
    state.$refs.calendar={showPicker(){throw new Error('Unsupported');}, classList:{remove(){visible++;}},focus(){focused++;}};
    state.openCalendar(); assert.equal(visible,1); assert.equal(focused,1);
});
