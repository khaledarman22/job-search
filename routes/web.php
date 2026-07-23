<?php

use App\Http\Controllers\TrackingController;
use Illuminate\Support\Facades\Route;

// بكسل تتبع فتح الإيميل — عام بلا مصادقة
Route::get('/t/{token}.gif', TrackingController::class)->name('tracking.pixel');

// SPA catch-all — كل المسارات غير /api و /t تروح للواجهة
Route::view('/{any?}', 'app')->where('any', '^(?!api|t/).*$');
