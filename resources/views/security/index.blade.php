@extends('layouts.app')
@section('title', __('security.title'))
@section('subtitle', __('common.system'))
@section('content')
    @livewire(App\Livewire\SecurityOverview::class)
@endsection
