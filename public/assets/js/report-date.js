/** Keep the readable D.M.YYYY field and native calendar synchronized without timezone conversion. */
window.rdReportDate = function (wire, field) {
    return {
        get iso() {
            const value = String(wire[field] || '');
            if (/^\d{4}-\d{2}-\d{2}$/.test(value)) return value;
            const match = /^(\d{1,2})\.(\d{1,2})\.(\d{4})$/.exec(value);
            return match ? `${match[3]}-${match[2].padStart(2, '0')}-${match[1].padStart(2, '0')}` : '';
        },
        select(value) {
            const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value);
            if (match) wire.$set(field, `${Number(match[3])}.${Number(match[2])}.${match[1]}`, true);
        },
        openCalendar() {
            const calendar = this.$refs.calendar;
            if (typeof calendar.showPicker === 'function') {
                try { calendar.showPicker(); return; } catch { /* Fall back to a visible native date field. */ }
            }
            calendar.classList.remove('visually-hidden');
            calendar.focus();
        }
    };
};
