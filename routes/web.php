<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

Route::get('/', function () {
    return response()->json([
        'name' => config('app.name'),
        'status' => 'ok',
    ]);
});

// Fallback for hosts (e.g. Wasmer WASIX) where public/storage symlinks are unavailable
Route::get('/storage/{path}', function (string $path) {
    $cleanPath = ltrim($path, '/');

    if (
        $cleanPath === '' ||
        str_contains($cleanPath, '..') ||
        str_ends_with($cleanPath, '.gitignore') ||
        ! Storage::disk('public')->exists($cleanPath)
    ) {
        abort(404);
    }

    return Storage::disk('public')->response($cleanPath, null, [
        'Cache-Control' => 'public, max-age=86400',
    ]);
})->where('path', '.*');
