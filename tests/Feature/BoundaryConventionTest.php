<?php

use App\Enums\Phase;
use App\Models\Conference;
use App\Models\Track;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('never lets application code read a raw track boundary', function () {
    $offending = collect(File::allFiles(app_path()))
        ->filter(fn (SplFileInfo $file) => str_contains($file->getContents(), 'getRawOriginal'))
        ->map(fn (SplFileInfo $file) => $file->getRelativePathname())
        ->values()
        ->all();

    expect($offending)->toBe([]);
});

it('keeps display formatting out of the model date columns', function () {
    foreach (Phase::cases() as $phase) {
        expect(method_exists(Track::class, 'get'.Str::studly($phase->openColumn()).'Attribute'))->toBeFalse()
            ->and(method_exists(Track::class, 'get'.Str::studly($phase->closeColumn()).'Attribute'))->toBeFalse();
    }

    expect(method_exists(Conference::class, 'getStartDateAttribute'))->toBeFalse()
        ->and(method_exists(Conference::class, 'getEndDateAttribute'))->toBeFalse();
});

it('has no global date helper left for an accessor to fall back into', function () {
    expect(function_exists('App\Helpers\format_date'))->toBeFalse()
        ->and(file_exists(app_path('Helpers/Helpers.php')))->toBeFalse();
});
