@extends('layouts.app')
@section('title', __('security.title'))
@section('content')
    @livewire(App\Livewire\SecurityOverview::class)
@endsection
