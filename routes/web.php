<?php

use App\Livewire\ControlPlane;
use Illuminate\Support\Facades\Route;

Route::get('/', ControlPlane::class);

// Serves local artwork (set cover images) into the webview. Image files only.
Route::get('/local-image', function () {
    $encoded = (string) request()->query('p', '');
    $path = base64_decode(strtr($encoded, '-_', '+/'), true);

    abort_unless(is_string($path) && $path !== '', 404);
    abort_unless(in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['png', 'jpg', 'jpeg', 'gif', 'webp'], true), 404);
    abort_unless(is_file($path), 404);

    return response()->file($path);
})->name('local-image');
