# Conference Management System

A web application for managing the peer-review process for academic conferences. Organizers set up
conferences and tracks; authors submit papers; committee members bid on submissions and review
assigned work; chairs manage assignments and record decisions.

The application is a single Laravel monolith with a Vue-based interface. Conference and track dates
govern when submission, bidding, review, and decision actions are available. Phase changes follow
the configured calendar; there is no manual phase-advance control.

## What the application does

- **Conference setup:** Organizers create conferences, tracks, fields, and date windows. Chairs manage
  the conference workflow and committee assignments.
- **Submissions:** Authors create and submit papers, maintain co-author information, and upload
  submission files. File downloads are served through authorization-checked routes.
- **Bidding and conflicts:** Committee members express interest in submissions and can declare
  conflicts of interest. Chairs can assign reviewers directly or use the bidding pool for
  assignments.
- **Review:** Reviewers accept or decline invitations, then submit a recommendation and comments for
  assigned work.
- **Decisions:** Chairs record an outcome and rationale. Outcomes include acceptance, rejection, and
  minor or major revision requests. Decision history is stored separately from the submission's
  current status.
- **Access control:** Roles include administrator, organizer, chair, committee member/reviewer, and
  author. Permissions can be global or scoped to a conference and are enforced by application
  policies.
- **Notifications:** Workflow events can notify authors, chairs, and reviewers by email through
  Laravel's queued notification system.

## Typical workflow

1. An organizer creates a conference and its tracks, including the relevant date windows.
2. Authors prepare submissions, add co-authors and files, and submit while the submission window is
   open.
3. Committee members bid on submissions and identify conflicts. Chairs assign reviewers manually or
   from the bidding pool.
4. Reviewers accept assignments and submit recommendations with comments during the review period.
5. Chairs make and record decisions, including any requested revision outcome.

## Technology

- PHP 8.4+ and Laravel 13
- Inertia.js v2, Vue 3, and TypeScript
- Tailwind CSS 4 and Vite
- Laravel Fortify for authentication
- Spatie Laravel Permission with team-based, conference-scoped roles
- SQLite for local development and tests; PostgreSQL for production deployments
- Pest for PHP feature and unit tests

## Repository map

| Path | Contents |
|---|---|
| `app/` | Laravel models, controllers, policies, services, enums, and notifications |
| `routes/` | Web, authentication, and settings routes |
| `resources/js/pages/` | Inertia pages grouped around conferences, submissions, bidding, assignments, and reviews |
| `resources/js/components/` | Shared Vue interface components |
| `resources/css/` | Application styles and Tailwind entry point |
| `database/` | Schema migrations, model factories, and seeders |
| `tests/` | Pest unit and feature tests for workflows, authorization, and phase boundaries |
| `bootstrap/`, `config/` | Laravel application bootstrap and configuration |
| `public/`, `storage/` | Web entry point, built assets, and application file storage |
| `Dockerfile`, `docker/`, `render.yaml` | Container and deployment configuration |

## Run locally

Requirements: PHP 8.4 or newer, Composer, Node.js 22 or newer, and npm. The default local database
is SQLite.

From a fresh checkout, configure the environment and install dependencies:

```sh
cp .env.example .env
composer install
npm install
touch database/database.sqlite
php artisan key:generate
php artisan migrate
```

To create the local role, reference, and demo-user data, run `php artisan db:seed` against a fresh or
disposable local database. The demo-user seeder deletes existing non-admin users before recreating
its sample accounts; do not run it against shared or production data.

Start the application and its development processes with:

```sh
composer dev
```

This starts the Laravel server, queue listener, log viewer, and Vite development server.

## Checks

```sh
composer test       # Pest test suite
npm run lint        # ESLint (applies automatic fixes)
npm run build       # Production frontend build
```
