<?php

namespace App\Http\Controllers;

use App\Models\ExpertiseArea;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ExpertiseAreaController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        return Inertia::render('ExpertiseAreas/Index', [
            'expertise_areas' => ExpertiseArea::with('users')->get(),
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(ExpertiseArea $expertiseArea)
    {
        return Inertia::render('ExpertiseAreas/Show', [
            'expertise_area' => $expertiseArea->load('users'),
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(ExpertiseArea $expertiseArea)
    {
        return Inertia::render('ExpertiseAreas/Edit', [
            'expertise_area' => $expertiseArea,
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, ExpertiseArea $expertiseArea)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
        ]);

        $expertiseArea->update($request->only(['name', 'description']));

        return redirect()->route('expertise-areas.index')->with('success', 'Expertise area updated successfully.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(ExpertiseArea $expertiseArea)
    {
        //
    }
}
