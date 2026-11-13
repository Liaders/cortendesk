@extends('layouts.app')

@section('title', __('organizations.section'))
@section('subtitle', __('common.manage'))

@section('content')
    @livewire(App\Livewire\GroupList::class)
@endsection
