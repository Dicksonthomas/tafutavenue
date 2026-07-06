<?php

use App\Http\Controllers\Api\ActivityLogController;
use App\Http\Controllers\Api\Admin\BookingAdminController;
use App\Http\Controllers\Api\Admin\ReportController;
use App\Http\Controllers\Api\Admin\SettingsController;
use App\Http\Controllers\Api\Admin\TimetableImportController;
use App\Http\Controllers\Api\Admin\UserAdminController;
use App\Http\Controllers\Api\Admin\VenueAdminController;
use App\Http\Controllers\Api\Auth\AuthController;
use App\Http\Controllers\Api\BookingController;
use App\Http\Controllers\Api\ReferenceDataController;
use App\Http\Controllers\Api\SemesterController;
use App\Http\Controllers\Api\TimetableSlotController;
use App\Http\Controllers\Api\VenueController;
use Illuminate\Support\Facades\Route;

// ---------------------------------------------------------------------
// PUBLIC (hakuna login inayohitajika)
// ---------------------------------------------------------------------
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

Route::get('/settings', [SettingsController::class, 'show']);
Route::get('/reference/faculties', [ReferenceDataController::class, 'faculties']);
Route::get('/reference/departments', [ReferenceDataController::class, 'departments']);
Route::get('/reference/programs', [ReferenceDataController::class, 'programs']);
Route::get('/reference/level-years', [ReferenceDataController::class, 'levelYears']);

// ---------------------------------------------------------------------
// CR / MTUMIAJI ALIYE-LOGIN (Sanctum bearer token)
// ---------------------------------------------------------------------
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/change-password', [AuthController::class, 'changePassword']);

    Route::get('/semesters', [SemesterController::class, 'index']);
    Route::post('/me/color-preference', [AuthController::class, 'updateColorPreference']);
    Route::get('/logs', [ActivityLogController::class, 'index']);
    Route::get('/timetable/by-lecturer', [TimetableSlotController::class, 'byLecturer']);

    Route::get('/venues', [VenueController::class, 'index']);
    Route::get('/venues/available', [VenueController::class, 'available']);
    Route::get('/venues/booked', [VenueController::class, 'booked']);
    Route::get('/venues/today-overview', [VenueController::class, 'todayOverview']);

    Route::get('/bookings', [BookingController::class, 'index']);
    Route::get('/bookings/{booking}', [BookingController::class, 'show']);
    Route::post('/bookings', [BookingController::class, 'store']);
    Route::post('/bookings/{booking}/sign', [BookingController::class, 'sign']);
    Route::post('/bookings/{booking}/cancel', [BookingController::class, 'cancel']);

    // -------------------------------------------------------------
    // ADMIN PEKEE
    // -------------------------------------------------------------
    Route::middleware('admin')->prefix('admin')->group(function () {
        Route::post('/semesters', [SemesterController::class, 'store']);
        Route::put('/semesters/{semester}', [SemesterController::class, 'update']);
        Route::post('/semesters/{semester}/activate', [SemesterController::class, 'activate']);

        Route::get('/venues', [VenueAdminController::class, 'index']);
        Route::post('/venues', [VenueAdminController::class, 'store']);
        Route::put('/venues/{venue}', [VenueAdminController::class, 'update']);
        Route::delete('/venues/{venue}', [VenueAdminController::class, 'destroy']);
        Route::post('/venues/import-timetable', [VenueAdminController::class, 'importTimetable']);
        Route::get('/venues/timetable-status', [VenueAdminController::class, 'timetableStatus']);

        Route::post('/timetable/import-from-link', [TimetableImportController::class, 'importFromLink']);

        Route::get('/bookings', [BookingAdminController::class, 'index']);
        Route::post('/bookings/{booking}/approve', [BookingAdminController::class, 'approve']);
        Route::post('/bookings/{booking}/reject', [BookingAdminController::class, 'reject']);

        Route::get('/reports/summary', [ReportController::class, 'summary']);
        Route::get('/reports/venues/{venue}', [ReportController::class, 'venueUsage']);

        Route::get('/users', [UserAdminController::class, 'index']);
        Route::post('/users', [UserAdminController::class, 'store']);
        Route::put('/users/{user}', [UserAdminController::class, 'update']);
        Route::delete('/users/{user}', [UserAdminController::class, 'destroy']);
        Route::get('/users/import-template', [UserAdminController::class, 'downloadTemplate']);
        Route::post('/users/import', [UserAdminController::class, 'importCsv']);

        Route::post('/settings', [SettingsController::class, 'update']);
    });
});
