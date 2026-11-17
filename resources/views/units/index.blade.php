@extends('layouts.app')
@section('title', __('Logements'))

@section('content')
    <div class="page-head">
        <h1>{{ __('Logements') }}</h1>
        <a class="btn" href="{{ route('units.create') }}">{{ __('Ajouter un logement') }}</a>
    </div>
    <div class="card">
        <form class="filters" method="GET">
            <div>
                <label for="property_id">{{ __('Bien') }}</label>
                <select id="property_id" name="property_id">
                    <option value="">{{ __('Tous') }}</option>
                    @foreach ($properties as $property)
                        <option value="{{ $property->id }}" @selected(request('property_id') == $property->id)>{{ $property->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="etat">{{ __('État') }}</label>
                <select id="etat" name="etat">
                    <option value="">{{ __('Tous') }}</option>
                    <option value="libres" @selected($state === 'libres')>{{ __('Libres') }}</option>
                    <option value="occupes" @selected($state === 'occupes')>{{ __('Occupés') }}</option>
                </select>
            </div>
            <button class="btn ghost">{{ __('Filtrer') }}</button>
        </form>
        @if ($units->isEmpty())
            <p class="empty">{{ __('Aucun logement.') }}</p>
        @else
            @include('units.table', ['units' => $units, 'showProperty' => true])
        @endif
    </div>
@endsection
