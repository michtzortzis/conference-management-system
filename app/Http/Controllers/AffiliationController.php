<?php

namespace App\Http\Controllers;

use App\Models\Affiliation;
use Illuminate\Http\Request;
use Inertia\Inertia;

class AffiliationController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        return Inertia::render('Affiliations/Index', [
            'affiliations' => Affiliation::with('users')->get(),
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
    public function show(Affiliation $affiliation)
    {
        return Inertia::render('Affiliations/Show', [
            'affiliation' => $affiliation->load('users'),
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Affiliation $affiliation)
    {
        return Inertia::render('Affiliations/Edit', [
            'affiliation' => $affiliation,
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Affiliation $affiliation)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'type' => 'required|string|max:255',
            'country' => 'nullable|string|max:255',
        ]);

        $affiliation->update($request->only(['name', 'type', 'country']));

        return redirect()->route('affiliations.index')->with('success', 'Affiliation updated successfully.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Affiliation $affiliation)
    {
        //
    }
}
