<?php

namespace App\Http\Controllers;

use App\Models\Conference;
use App\Models\Field;
use App\Models\User;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Spatie\Permission\PermissionRegistrar;

class ConferenceController extends Controller
{
    use AuthorizesRequests;

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $conferences = Conference::with(['fields', 'tracks', 'submissions'])->get();

        return Inertia::render('Conferences/Index', [
            'conferences' => $conferences,
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        $this->authorize('create', Conference::class);

        return Inertia::render('Conferences/Create');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $this->authorize('create', Conference::class);
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'start_date' => 'nullable|date|before_or_equal:today',
            'end_date' => 'nullable|date|after_or_equal:start_date|after_or_equal:today',
            'fields' => 'nullable|array',
            'fields.*.id' => 'nullable', // Accept string or number
            'fields.*.name' => 'required|string|max:150',
            'fields.*.description' => 'nullable|string',
            'tracks' => 'required|array|min:1',
            'tracks.*.name' => 'required|string|max:255',
            'tracks.*.description' => 'nullable|string',
            'tracks.*.submission_open_at' => 'required|date|before_or_equal:today',
            'tracks.*.submission_close_at' => 'required|date|after_or_equal:tracks.*.submission_open_at|after_or_equal:today',
            'tracks.*.review_open_at' => 'nullable|date|after_or_equal:tracks.*.submission_close_at',
            'tracks.*.review_close_at' => 'nullable|date|after_or_equal:tracks.*.review_open_at',
            'tracks.*.decision_open_at' => 'nullable|date|after_or_equal:tracks.*.review_close_at',
            'tracks.*.decision_close_at' => 'nullable|date|after_or_equal:tracks.*.decision_open_at',
            'double_blind_review' => 'sometimes|boolean',
            'coi_auto_detect' => 'sometimes|boolean',
        ]);

        $conference = Conference::create($validated);

        // Handle fields
        $fieldIds = [];
        if (! empty($request->fields)) {
            foreach ($request->fields as $field) {
                if (! empty($field['id']) && is_numeric($field['id'])) {
                    $fieldIds[] = (int) $field['id'];
                } elseif (! empty($field['name'])) {
                    $newField = Field::firstOrCreate(
                        ['name' => $field['name']],
                        ['description' => $field['description'] ?? null]
                    );
                    $fieldIds[] = $newField->id;
                }
            }
        }
        if ($fieldIds) {
            $conference->fields()->sync($fieldIds);
        }

        // Handle tracks (must have at least one)
        $tracks = $request->input('tracks', []);
        foreach ($tracks as $track) {
            $conference->tracks()->create([
                'conference_id' => $conference->id,
                'name' => $track['name'],
                'description' => $track['description'] ?? null,
                'submission_open_at' => $track['submission_open_at'] ?? null,
                'submission_close_at' => $track['submission_close_at'] ?? null,
                'review_open_at' => $track['review_open_at'] ?? null,
                'review_close_at' => $track['review_close_at'] ?? null,
                'decision_open_at' => $track['decision_open_at'] ?? null,
                'decision_close_at' => $track['decision_close_at'] ?? null,
            ]);
        }

        $this->assignConferenceRole($request->user(), 'chair', $conference->id);

        return redirect()->route('conferences.index')->with('success', 'Conference created successfully.');
    }

    /**
     * Display the specified resource.
     */
    public function show(Conference $conference)
    {
        setPermissionsTeamId($conference->id);
        $chair = User::role('chair')->first();
        $committee_members = User::role('committee_member')->get();
        setPermissionsTeamId(null);

        return Inertia::render('Conferences/Show', [
            'conference' => $conference->load(['fields', 'tracks', 'submissions.user', 'submissions.track']),
            'chair' => $chair,
            'committee_members' => $committee_members,
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Conference $conference)
    {
        $this->authorize('update', $conference);
        setPermissionsTeamId($conference->id);
        $chair = User::role('chair')->first();
        $committee_members = User::role('committee_member')->get();
        setPermissionsTeamId(null);

        return Inertia::render('Conferences/Edit', [
            'conference' => $conference->load(['fields', 'tracks']),
            'chair' => $chair,
            'committee_members' => $committee_members,
            // Appointment pool. `update()` validates `chair_user_id` and `committee_members.*` as
            // plain `exists:users,id`, so the pickers must offer every account — narrowing this to
            // current role-holders would make it impossible to appoint anyone new. Shipped only
            // through this route, which `authorize('update')` already gates: the former
            // `GET /users` handed the identical list to any verified user with no policy at all
            // (D-4.3), which is the leak this replaces.
            'user_candidates' => User::query()
                ->orderBy('last_name')
                ->orderBy('first_name')
                ->get(['id', 'first_name', 'last_name', 'email']),
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Conference $conference)
    {
        $this->authorize('update', $conference);

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'fields' => 'nullable|array',
            'fields.*.id' => 'nullable', // Accept string or number
            'fields.*.name' => 'required|string|max:150',
            'fields.*.description' => 'nullable|string',
            'tracks' => 'required|array|min:1',
            'tracks.*.id' => 'nullable|integer|exists:tracks,id',
            'tracks.*.name' => 'required|string|max:255',
            'tracks.*.description' => 'nullable|string',
            'tracks.*.submission_open_at' => 'required|date',
            'tracks.*.submission_close_at' => 'required|date|after_or_equal:tracks.*.submission_open_at',
            'tracks.*.review_open_at' => 'nullable|date|after_or_equal:tracks.*.submission_close_at',
            'tracks.*.review_close_at' => 'nullable|date|after_or_equal:tracks.*.review_open_at',
            'tracks.*.decision_open_at' => 'nullable|date|after_or_equal:tracks.*.review_close_at',
            'tracks.*.decision_close_at' => 'nullable|date|after_or_equal:tracks.*.decision_open_at',
            'chair_user_id' => 'nullable|exists:users,id',
            'committee_members' => 'nullable|array',
            'committee_members.*' => 'exists:users,id',
            'double_blind_review' => 'sometimes|boolean',
            'coi_auto_detect' => 'sometimes|boolean',
        ]);

        $conference->update([
            'title' => $validated['title'],
            'description' => $validated['description'],
            'start_date' => $validated['start_date'] ?? $conference->start_date,
            'end_date' => $validated['end_date'] ?? $conference->end_date,
            'double_blind_review' => $validated['double_blind_review'] ?? $conference->double_blind_review,
            'coi_auto_detect' => $validated['coi_auto_detect'] ?? $conference->coi_auto_detect,
        ]);

        // Handle fields
        $fieldIds = [];
        if (! empty($request->fields)) {
            foreach ($request->fields as $field) {
                if (! empty($field['id']) && is_numeric($field['id'])) {
                    $fieldIds[] = (int) $field['id'];
                } elseif (! empty($field['name'])) {
                    $newField = Field::firstOrCreate(
                        ['name' => $field['name']],
                        ['description' => $field['description'] ?? null]
                    );
                    $fieldIds[] = $newField->id;
                }
            }
        }
        $conference->fields()->sync($fieldIds);

        // Handle tracks: update existing, create new, soft-delete removed
        $tracks = $request->input('tracks', []);
        $trackIdsToKeep = [];
        foreach ($tracks as $track) {
            $trackData = [
                'name' => $track['name'],
                'description' => $track['description'] ?? null,
                'submission_open_at' => $track['submission_open_at'] ?? null,
                'submission_close_at' => $track['submission_close_at'] ?? null,
                'review_open_at' => $track['review_open_at'] ?? null,
                'review_close_at' => $track['review_close_at'] ?? null,
                'decision_open_at' => $track['decision_open_at'] ?? null,
                'decision_close_at' => $track['decision_close_at'] ?? null,
            ];
            if (! empty($track['id'])) {
                $existingTrack = $conference->tracks()->find($track['id']);
                if ($existingTrack) {
                    $existingTrack->update($trackData);
                    $trackIdsToKeep[] = $existingTrack->id;
                } else {
                    $newTrack = $conference->tracks()->create($trackData);
                    $trackIdsToKeep[] = $newTrack->id;
                }
            } else {
                $newTrack = $conference->tracks()->create($trackData);
                $trackIdsToKeep[] = $newTrack->id;
            }
        }
        // Soft-delete tracks that were not included in the request
        $conference->tracks()
            ->whereNotIn('id', $trackIdsToKeep)
            ->delete();

        // Assign chair role if provided
        if ($request->filled('chair_user_id')) {
            $user = User::find($request->chair_user_id);
            $this->assignConferenceRole($user, 'chair', $conference->id);
        }

        // Assign committee member roles if provided
        if ($request->filled('committee_members')) {
            foreach ($request->committee_members as $userId) {
                $user = User::find($userId);
                $this->assignConferenceRole($user, 'committee_member', $conference->id);
            }
        }

        return redirect()->route('conferences.index')->with('success', 'Conference updated successfully.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Conference $conference)
    {
        $this->authorize('delete', $conference);
        $conference->delete();

        return redirect()->route('conferences.index')->with('success', 'Conference deleted successfully.');
    }

    private function assignConferenceRole(User $user, string $role, int $conferenceId): void
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($conferenceId);

        try {
            $user->assignRole($role);
        } finally {
            app(PermissionRegistrar::class)->setPermissionsTeamId(null);
        }
    }
}
