<?php

use Illuminate\Support\Facades\Route;

// API-only service. The portal is the Next.js app in ../frontend.
Route::get('/', fn () => response()->json([
    'service' => 'NguzoPay API',
    'version' => 'v1',
    'docs' => '/api/v1/meta',
]));
