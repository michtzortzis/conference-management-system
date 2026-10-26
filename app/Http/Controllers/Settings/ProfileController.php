<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use App\Models\Affiliation;
use App\Models\ExpertiseArea;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Show the user's profile settings page.
     */
    public function edit(Request $request): Response
    {
        $user = $request->user()->load([
            'affiliations' => function ($q) {
                $q->select('affiliations.id', 'name');
            },
            'expertiseAreas' => function ($q) {
                $q->select('expertise_areas.id', 'name');
            },
        ]);

        $affiliations = Affiliation::orderBy('name')->get(['id', 'name']);
        $expertiseAreas = ExpertiseArea::orderBy('name')->get(['id', 'name']);

        // Map user's affiliations with pivot data
        $userAffiliations = $user->affiliations->map(function ($aff) {
            return [
                'id' => $aff->id,
                'name' => $aff->name,
                'department' => $aff->pivot->department ?? null,
                'position' => $aff->pivot->position ?? null,
            ];
        });

        // Map user's expertise areas with pivot data
        $userExpertiseAreas = $user->expertiseAreas->map(function ($ex) {
            return [
                'id' => $ex->id,
                'name' => $ex->name,
                'years_experience' => $ex->pivot->years_experience ?? null,
            ];
        });

        return Inertia::render('settings/Profile', [
            'mustVerifyEmail' => $user instanceof MustVerifyEmail,
            'status' => $request->session()->get('status'),
            'affiliations' => $affiliations,
            'expertise_areas' => $expertiseAreas,
            'user_affiliations' => $userAffiliations,
            'user_expertise_areas' => $userExpertiseAreas,
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validated();

        $user->fill([
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'phone' => $data['phone'] ?? null,
            'email' => $data['email'],
        ]);

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $user->save();

        // Always sync affiliations (treat missing as empty)
        $affiliationData = [];
        $affiliations = $data['affiliations'] ?? [];
        if (is_array($affiliations)) {
            foreach ($affiliations as $item) {
                $affiliationId = null;
                $department = $item['department'] ?? null;
                $position = $item['position'] ?? null;

                if (isset($item['id'])) {
                    if (is_numeric($item['id'])) {
                        $affiliationId = (int) $item['id'];
                    } elseif (is_string($item['id']) && trim($item['id']) !== '') {
                        $name = trim($item['id']);
                        $aff = Affiliation::firstOrCreate(['name' => $name], ['type' => 'other']);
                        $affiliationId = $aff->id;
                    }
                }

                if ($affiliationId) {
                    $affiliationData[$affiliationId] = array_filter([
                        'department' => $department,
                        'position' => $position,
                    ], fn ($value) => $value !== null);
                }
            }
        }
        $user->affiliations()->sync($affiliationData);

        // Always sync expertise areas (treat missing as empty)
        $expertiseData = [];
        $expertiseAreas = $data['expertise_areas'] ?? [];
        if (is_array($expertiseAreas)) {
            foreach ($expertiseAreas as $item) {
                $expertiseId = null;
                $yearsExperience = $item['years_experience'] ?? null;

                if (isset($item['id'])) {
                    if (is_numeric($item['id'])) {
                        $expertiseId = (int) $item['id'];
                    } elseif (is_string($item['id']) && trim($item['id']) !== '') {
                        $name = trim($item['id']);
                        $ex = ExpertiseArea::firstOrCreate(['name' => $name], ['description' => null]);
                        $expertiseId = $ex->id;
                    }
                }

                if ($expertiseId) {
                    $expertiseData[$expertiseId] = array_filter([
                        'years_experience' => $yearsExperience,
                    ], fn ($value) => $value !== null);
                }
            }
        }
        $user->expertiseAreas()->sync($expertiseData);

        return to_route('profile.edit');
    }

    /**
     * Delete the user's profile.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->validate([
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
