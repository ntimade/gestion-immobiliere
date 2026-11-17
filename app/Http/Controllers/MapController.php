<?php

namespace App\Http\Controllers;

use App\Models\Property;
use Illuminate\View\View;

class MapController extends Controller
{
    public function index(): View
    {
        $properties = Property::query()
            ->withCount(['units', 'units as occupied_count' => fn ($unit) => $unit->occupied()])
            ->withIncome()
            ->orderBy('name')->get();

        $placed = $properties->filter(fn (Property $property) => $property->hasPosition());

        return view('map.index', [
            'withoutPosition' => $properties->count() - $placed->count(),
            'points' => $placed->values()->map(fn (Property $property) => [
                'lat' => (float) $property->latitude,
                'lng' => (float) $property->longitude,
                'name' => $property->name,
                'place' => $property->place(),
                'units' => (int) $property->units_count,
                'occupied' => (int) $property->occupied_count,
                'income' => money($property->current_income),
                'estimated' => money($property->estimated_income),
                'status' => match (true) {
                    $property->units_count === 0 || $property->occupied_count === 0 => 'empty',
                    $property->occupied_count < $property->units_count => 'partial',
                    default => 'full',
                },
                'url' => route('properties.show', $property),
            ]),
        ]);
    }
}
