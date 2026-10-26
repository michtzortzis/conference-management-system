<?php

namespace App\Http\Controllers;

use App\Enums\ReviewAssignmentStatus;
use App\Enums\SubmissionStatus;
use App\Models\Conference;
use App\Models\ReviewAssignment;
use App\Models\Submission;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Spatie\Permission\PermissionRegistrar;

class DashboardController extends Controller
{
    /**
     * Display a role-aware dashboard scoped to the authenticated user.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $permissionRegistrar = app(PermissionRegistrar::class);

        // Global roles (team_id = 0). Use a fresh relation + unset the cache so
        // the global role relation is not reused later.
        $permissionRegistrar->setPermissionsTeamId(0);
        $globalRoles = $user->roles()->pluck('name')->toArray();
        $permissionRegistrar->setPermissionsTeamId(null);
        $user->unsetRelation('roles');

        $isOrganizer = in_array('organizer', $globalRoles);
        $isAdmin = in_array('admin', $globalRoles);

        // Only active (non-trashed) conferences are surfaced on the dashboard.
        $conferences = Conference::orderBy('title')->get(['id', 'title']);
        $activeConferenceIds = $conferences->pluck('id')->all();

        // Conference-scoped roles: fetch all non-global roles in a single pivot
        // query, keyed by conference (team) id. Trashed conferences are skipped.
        $conferenceRoles = [];
        $roleRows = DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_type', get_class($user))
            ->where('model_has_roles.model_id', $user->id)
            ->where('model_has_roles.team_id', '>', 0)
            ->select('roles.name', 'model_has_roles.team_id')
            ->get();

        foreach ($roleRows as $row) {
            if (! in_array($row->team_id, $activeConferenceIds)) {
                continue;
            }

            $conferenceRoles[$row->team_id][] = $row->name;
        }

        $chairConferenceIds = [];
        $committeeConferenceIds = [];

        foreach ($conferenceRoles as $conferenceId => $roles) {
            if (in_array('chair', $roles)) {
                $chairConferenceIds[] = $conferenceId;
            }
            if (in_array('committee_member', $roles)) {
                $committeeConferenceIds[] = $conferenceId;
            }
        }

        // My Submissions
        $mySubmissionsCount = Submission::where('user_id', $user->id)->count();

        // Pending Reviews — the user's own review workload.
        $pendingReviewsCount = ReviewAssignment::where('reviewer_id', $user->id)
            ->whereIn('status', [
                ReviewAssignmentStatus::Pending->value,
                ReviewAssignmentStatus::Accepted->value,
            ])
            ->count();

        // My Conferences — organizers/admins manage globally; everyone else only
        // sees conferences where they hold a conference-scoped role.
        $myConferenceIds = array_keys($conferenceRoles);

        if ($isOrganizer || $isAdmin) {
            $myConferences = $conferences;
        } else {
            $myConferences = $conferences->whereIn('id', $myConferenceIds);
        }

        // Recent Submissions — always the user's own; chairs/committee members
        // also see their conferences' recent ones. Organizers/admins see all.
        // Committee members never see other authors' draft submissions.
        $recentSubmissions = Submission::query()
            ->with(['conference', 'track'])
            ->where(function ($query) use ($user, $chairConferenceIds, $committeeConferenceIds, $isOrganizer, $isAdmin) {
                if ($isOrganizer || $isAdmin) {
                    return;
                }

                $query->where('user_id', $user->id);

                if (! empty($chairConferenceIds)) {
                    $query->orWhereIn('conference_id', $chairConferenceIds);
                }

                if (! empty($committeeConferenceIds)) {
                    $query->orWhere(function ($q) use ($committeeConferenceIds) {
                        $q->whereIn('conference_id', $committeeConferenceIds)
                            ->where('status', '!=', SubmissionStatus::Draft->value);
                    });
                }
            })
            ->latest()
            ->limit(10)
            ->get();

        // Global aggregate counts — only for organizers/admins; a plain author
        // must never see aggregate submission counts (leaks others' data).
        $globalCounts = null;

        if ($isOrganizer || $isAdmin) {
            $globalCounts = [
                'conferences' => Conference::count(),
                'submissions' => Submission::count(),
            ];
        }

        return Inertia::render('Dashboard', [
            'stats' => [
                'my_submissions' => $mySubmissionsCount,
                'pending_reviews' => $pendingReviewsCount,
                'my_conferences' => $myConferences->count(),
            ],
            'myConferences' => $myConferences
                ->map(fn ($conference) => ['id' => $conference->id, 'title' => $conference->title])
                ->values(),
            'recentSubmissions' => $recentSubmissions,
            'globalCounts' => $globalCounts,
        ]);
    }
}
