<div class="col-md-4" x-data="rdReportDate($wire, @js($field))">
    <label class="form-label" for="{{ $id }}">{{ $caption }}</label>
    <div class="input-group position-relative">
        <input id="{{ $id }}" type="text" inputmode="numeric" placeholder="1.11.2026" class="form-control"
               wire:model.live.debounce.500ms="{{ $field }}" aria-describedby="report-date-hint">
        <button type="button" class="btn btn-outline-secondary" @click="openCalendar()" aria-label="{{ __('console_usability.open_calendar', ['field' => $caption]) }}"><i class="ri-calendar-line" aria-hidden="true"></i></button>
        <input type="date" class="visually-hidden" tabindex="-1" x-ref="calendar" :value="iso" @change="select($event.target.value)" aria-label="{{ __('console_usability.open_calendar', ['field' => $caption]) }}">
    </div>
    @error($field)<span class="text-danger">{{ $message }}</span>@enderror
</div>
