<?php

namespace App\Http\Controllers\Auth;

use App\Enums\ReviewAssignmentStatus;
use App\Http\Controllers\Controller;
use App\Models\Affiliation;
use App\Models\ExpertiseArea;
use App\Models\ReviewAssignment;
use App\Models\SubmissionCoAuthor;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class RegisteredUserController extends Controller
{
    /**
     * Show the registration page.
     */
    public function create(): Response
    {
        $affiliations = Affiliation::orderBy('name')->get(['id', 'name']);
        $expertiseAreas = ExpertiseArea::orderBy('name')->get(['id', 'name']);

        return Inertia::render('auth/Register', [
            'affiliations' => $affiliations,
            'expertise_areas' => $expertiseAreas,
        ]);
    }

    /**
     * Handle an incoming registration request.
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:13',
            'email' => 'required|string|lowercase|email|max:255|unique:'.User::class,
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            'affiliations' => 'nullable|array',
            'affiliations.*.id' => 'nullable',
            'affiliations.*.department' => 'nullable|string|max:255',
            'affiliations.*.position' => 'nullable|string|max:255',
            'expertise_areas' => 'nullable|array',
            'expertise_areas.*.id' => 'nullable',
            'expertise_areas.*.years_experience' => 'nullable|integer|min:0|max:100',
        ]);

        $user = User::create([
            'first_name' => $request->first_name,
            'last_name' => $request->last_name,
            'phone' => $request->phone,
            'email' => $request->email,
            'password' => Hash::make($request->password),
        ]);

        // attach optional affiliations and expertise areas if provided
        if ($request->filled('affiliations') && is_array($request->affiliations)) {
            $affiliationData = [];
            foreach ($request->affiliations as $item) {
                $affiliationId = null;
                $department = $item['department'] ?? null;
                $position = $item['position'] ?? null;

                if (isset($item['id'])) {
                    if (is_numeric($item['id'])) {
                        // Existing affiliation by ID
                        $affiliationId = (int) $item['id'];
                    } elseif (is_string($item['id']) && trim($item['id']) !== '') {
                        // Create new affiliation from string
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

            if (! empty($affiliationData)) {
                $user->affiliations()->attach($affiliationData);
            }
        }

        if ($request->filled('expertise_areas') && is_array($request->expertise_areas)) {
            $expertiseData = [];
            foreach ($request->expertise_areas as $item) {
                $expertiseId = null;
                $yearsExperience = $item['years_experience'] ?? null;

                if (isset($item['id'])) {
                    if (is_numeric($item['id'])) {
                        // Existing expertise area by ID
                        $expertiseId = (int) $item['id'];
                    } elseif (is_string($item['id']) && trim($item['id']) !== '') {
                        // Create new expertise area from string
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

            if (! empty($expertiseData)) {
                $user->expertiseAreas()->attach($expertiseData);
            }
        }

        $this->claimCoAuthorships($user);

        event(new Registered($user));

        Auth::login($user);

        $request->session()->regenerate();

        return to_route('dashboard');
    }

    /**
     * Claim any outstanding co-author invitations matching the new user's
     * email. The claiming user becomes a registered co-author on the
     * submission(s); if they had a review assignment on that submission, the
     * assignment is invalidated so they are not reviewing their own paper.
     */
    private function claimCoAuthorships(User $user): void
    {
        $invitations = SubmissionCoAuthor::where('email', $user->email)
            ->whereNull('user_id')
            ->get();

        if ($invitations->isEmpty()) {
            return;
        }

        foreach ($invitations as $invitation) {
            $invitation->update([
                'user_id' => $user->id,
                'invitation_accepted_at' => now(),
            ]);

            ReviewAssignment::where('submission_id', $invitation->submission_id)
                ->where('reviewer_id', $user->id)
                ->whereIn('status', [ReviewAssignmentStatus::Pending, ReviewAssignmentStatus::Accepted])
                ->update(['status' => ReviewAssignmentStatus::Declined]);
        }
    }
}
