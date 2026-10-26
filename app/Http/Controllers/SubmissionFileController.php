<?php

namespace App\Http\Controllers;

use App\Models\Submission;
use App\Models\SubmissionFile;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class SubmissionFileController extends Controller
{
    use AuthorizesRequests;

    /**
     * Store a newly created submission file.
     */
    public function store(Request $request, Submission $submission): RedirectResponse
    {
        $this->authorize('uploadFile', $submission);

        $validated = $request->validate([
            'files' => 'required|array|min:1',
            'files.*' => 'file|max:20480|mimes:pdf,doc,docx,txt',
        ]);

        $existingCount = $submission->files()->count();
        $newFileCount = count($validated['files']);
        $maxFiles = 5;

        if ($existingCount + $newFileCount > $maxFiles) {
            return back()->withErrors([
                'files' => "Maximum {$maxFiles} files allowed per submission. You currently have {$existingCount}.",
            ]);
        }

        $disk = Storage::disk('local');
        $directory = "submissions/{$submission->id}";
        $storedCount = 0;

        foreach ($validated['files'] as $file) {
            $filename = Str::uuid().'.'.$file->getClientOriginalExtension();
            $path = $disk->putFileAs($directory, $file, $filename);

            if ($path) {
                $submission->files()->create([
                    'filename' => $file->getClientOriginalName(),
                    'path' => $path,
                    'mime_type' => $file->getMimeType(),
                    'size' => $file->getSize(),
                ]);
                $storedCount++;
            }
        }

        if ($storedCount === 0) {
            return back()->withErrors(['files' => 'No files could be stored. Please try again.']);
        }

        return back()->with('success', "{$storedCount} file(s) uploaded successfully.");
    }

    /**
     * Download a submission file.
     */
    public function download(Submission $submission, SubmissionFile $file): Response
    {
        if ($file->submission_id !== $submission->id) {
            abort(404);
        }

        $this->authorize('downloadFile', $file);

        if (! Storage::disk('local')->exists($file->path)) {
            abort(404);
        }

        return Storage::disk('local')->download($file->path, $file->filename, [
            'Content-Type' => $file->mime_type ?? 'application/octet-stream',
        ]);
    }

    /**
     * Remove a submission file.
     */
    public function destroy(Request $request, Submission $submission, SubmissionFile $file): RedirectResponse
    {
        if ($file->submission_id !== $submission->id) {
            abort(404);
        }

        $this->authorize('deleteFile', $file);

        // Move to trash directory so it can be restored alongside the soft-deleted record
        $trashPath = 'submissions-trash/'.basename($file->path);
        if (Storage::disk('local')->exists($file->path)) {
            Storage::disk('local')->move($file->path, $trashPath);
        }

        $file->delete();

        return back()->with('success', 'File deleted successfully.');
    }
}
