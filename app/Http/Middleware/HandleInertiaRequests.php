<?php

namespace App\Http\Middleware;

use App\Models\Conference;
use Illuminate\Foundation\Inspiring;
use Illuminate\Http\Request;
use Inertia\Middleware;
use Spatie\Permission\PermissionRegistrar;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        [$message, $author] = str(Inspiring::quotes()->random())->explode('-');

        $permissionRegistrar = app(PermissionRegistrar::class);
        $auth = [
            'user' => $request->user(),
        ];

        if ($request->user()) {
            $user = $request->user();

            $resolveRolesForTeam = static function (int $teamId) use ($permissionRegistrar, $user): array {
                $permissionRegistrar->setPermissionsTeamId($teamId);

                // Use a fresh relation query so team-scoped role lookups are not stale.
                return $user->roles()->pluck('name')->toArray();
            };

            $globalRoles = $resolveRolesForTeam(0);

            $conferenceRoles = [];
            $conferenceIds = Conference::withTrashed()->pluck('id');

            foreach ($conferenceIds as $conferenceId) {
                $userRoles = $resolveRolesForTeam($conferenceId);
                if (! empty($userRoles)) {
                    $conferenceRoles[$conferenceId] = $userRoles;
                }
            }

            $permissionRegistrar->setPermissionsTeamId(null);

            $auth['roles'] = [
                'global' => $globalRoles,
                'conferences' => $conferenceRoles,
            ];
        }

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'quote' => ['message' => trim($message), 'author' => trim($author)],
            'auth' => $auth,
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }
}
