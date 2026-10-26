<?php

use App\Http\Controllers\AffiliationController;
use App\Http\Controllers\BidController;
use App\Http\Controllers\ConferenceController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DecisionController;
use App\Http\Controllers\ExpertiseAreaController;
use App\Http\Controllers\FieldController;
use App\Http\Controllers\ReviewController;
use App\Http\Controllers\SubmissionController;
use App\Http\Controllers\SubmissionFileController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', function (Request $request) {
    // The login page has exactly one entry point (route('login')); the root URL
    // only routes. Rendering auth/Login here instead would render it without
    // the props it requires (SD-13).
    return redirect()->route($request->user() ? 'dashboard' : 'login');
})->name('home');

Route::get('dashboard', [DashboardController::class, 'index'])
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Route::resource('affiliations', AffiliationController::class)->middleware(['auth', 'verified']);
Route::resource('expertise-areas', ExpertiseAreaController::class)->middleware(['auth', 'verified']);
Route::resource('conferences', ConferenceController::class)->middleware(['auth', 'verified']);

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('submissions', SubmissionController::class);
    Route::post('submissions/{submission}/submit', [SubmissionController::class, 'submit'])
        ->name('submissions.submit');

    // File management routes (policy-authorized via controller)
    Route::post('submissions/{submission}/files', [SubmissionFileController::class, 'store'])
        ->name('submissions.files.store');
    Route::get('submissions/{submission}/files/{file}/download', [SubmissionFileController::class, 'download'])
        ->name('submissions.files.download');
    Route::delete('submissions/{submission}/files/{file}', [SubmissionFileController::class, 'destroy'])
        ->name('submissions.files.destroy');

    // Route::middleware(['can:create,App\Models\Submission'])->resource('conferences.submissions', App\Http\Controllers\SubmissionController::class)->except(['index']);

    Route::post('submissions/{submission}/assign-reviewer', [SubmissionController::class, 'assignReviewer'])
        ->name('submissions.assign-reviewer');

    Route::post('submissions/{submission}/auto-assign', [SubmissionController::class, 'autoAssignBidders'])
        ->name('submissions.auto-assign');

    Route::post('submissions/{submission}/start-review', [SubmissionController::class, 'startReview'])
        ->name('submissions.start-review');

    Route::get('bidding', [BidController::class, 'index'])
        ->name('bidding.index');
    Route::post('submissions/{submission}/bid', [BidController::class, 'store'])
        ->name('submissions.bid');
    Route::delete('submissions/{submission}/unbid', [BidController::class, 'destroy'])
        ->name('submissions.unbid');

    Route::get('assignments', [SubmissionController::class, 'assignmentOverview'])
        ->name('assignments.index');

    Route::post('submissions/{submission}/conflicts', [SubmissionController::class, 'declareConflict'])
        ->name('submissions.conflicts.store');

    Route::post('submissions/{submission}/conflicts/{reviewer}/override', [SubmissionController::class, 'overrideConflict'])
        ->name('submissions.conflicts.override');

    Route::post('submissions/{submission}/decision', [DecisionController::class, 'store'])
        ->name('submissions.decision.store');
});

Route::resource('reviews', ReviewController::class)->middleware(['auth', 'verified']);

Route::post('reviews/{assignment}/accept', [ReviewController::class, 'accept'])
    ->name('reviews.accept');
Route::post('reviews/{assignment}/decline', [ReviewController::class, 'decline'])
    ->name('reviews.decline');
Route::post('reviews/{assignment}/self-conflict', [ReviewController::class, 'selfDeclareConflict'])
    ->name('reviews.self-conflict');

// D-4.3: the former `/users` closure (returned every account's id/name/email to any
// verified user, with no policy and no Gate::before to hide behind) is deleted.
// IN-13 in §3.4.6 claimed it was gone; now the sentence is true. No admin UI ships in
// the MVP — admin surface is deferred scope (D-4.3), role grants stay out-of-band.

Route::resource('fields', FieldController::class)->middleware(['auth', 'verified']);

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
