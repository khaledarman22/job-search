<?php

use App\Http\Controllers\Api\ApifyRunController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ContactController;
use App\Http\Controllers\Api\ContactImportController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\JobPostController;
use App\Http\Controllers\Api\OutreachEmailController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\SourceController;
use Illuminate\Support\Facades\Route;

// login بره مجموعة المصادقة — Throttle 5/دقيقة زي العقد
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');

Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);

    Route::get('/dashboard', DashboardController::class);

    Route::get('/sources', [SourceController::class, 'index']);
    Route::post('/sources', [SourceController::class, 'store']);
    Route::put('/sources/{source}', [SourceController::class, 'update']);
    Route::delete('/sources/{source}', [SourceController::class, 'destroy']);
    Route::post('/sources/{source}/run', [SourceController::class, 'run']);
    Route::post('/sources/{source}/test', [SourceController::class, 'test']);

    Route::get('/runs', [ApifyRunController::class, 'index']);
    Route::post('/runs/sync', [ApifyRunController::class, 'sync']);
    Route::get('/runs/{run}', [ApifyRunController::class, 'show']);
    Route::post('/runs/{run}/import', [ApifyRunController::class, 'import']);

    Route::get('/job-posts', [JobPostController::class, 'index']);
    Route::get('/job-posts/{jobPost}', [JobPostController::class, 'show']);
    Route::post('/job-posts/{jobPost}/queue', [JobPostController::class, 'queue']);
    Route::post('/companies/{company}/enrich', [JobPostController::class, 'enrichCompany']);

    Route::get('/contacts', [ContactController::class, 'index']);
    Route::post('/contacts/import', [ContactImportController::class, 'store']);
    Route::patch('/contacts/{contact}', [ContactController::class, 'update']);
    Route::post('/contacts/{contact}/queue', [ContactController::class, 'queue']);
    Route::post('/contacts/{contact}/suppress', [ContactController::class, 'suppress']);
    Route::post('/contacts/{contact}/unsuppress', [ContactController::class, 'unsuppress']);

    Route::get('/emails', [OutreachEmailController::class, 'index']);
    Route::get('/emails/{email}', [OutreachEmailController::class, 'show']);
    Route::post('/emails/{email}/cancel', [OutreachEmailController::class, 'cancel']);
    Route::post('/emails/{email}/requeue', [OutreachEmailController::class, 'requeue']);
    Route::patch('/emails/{email}', [OutreachEmailController::class, 'update']);
    Route::patch('/queue/reorder', [OutreachEmailController::class, 'reorder']);
    Route::post('/queue/pause', [OutreachEmailController::class, 'pause']);
    Route::post('/queue/resume', [OutreachEmailController::class, 'resume']);

    Route::get('/settings', [SettingsController::class, 'show']);
    Route::put('/settings/apify', [SettingsController::class, 'updateApify']);
    Route::put('/settings/smtp', [SettingsController::class, 'updateSmtp']);
    Route::post('/settings/smtp-test', [SettingsController::class, 'smtpTest']);
    Route::put('/settings/template', [SettingsController::class, 'updateTemplate']);
    Route::post('/settings/template-preview', [SettingsController::class, 'templatePreview']);
    Route::put('/settings/schedule', [SettingsController::class, 'updateSchedule']);
    Route::put('/settings/auto-scrape', [SettingsController::class, 'updateAutoScrape']);
    Route::put('/settings/profile', [SettingsController::class, 'updateProfile']);
    Route::post('/settings/cv', [SettingsController::class, 'uploadCv']);
    Route::get('/settings/cv', [SettingsController::class, 'downloadCv']);
});
