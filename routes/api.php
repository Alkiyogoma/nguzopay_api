<?php

use App\Http\Controllers\Api\V1\Admin;
use App\Http\Controllers\Api\V1\Auth\EmailVerificationController;
use App\Http\Controllers\Api\V1\Auth\OtpAuthController;
use App\Http\Controllers\Api\V1\Auth\PasswordAuthController;
use App\Http\Controllers\Api\V1\Auth\PasswordResetController;
use App\Http\Controllers\Api\V1\Auth\SocialAuthController;
use App\Http\Controllers\Api\V1\BusinessAuditController;
use App\Http\Controllers\Api\V1\BusinessController;
use App\Http\Controllers\Api\V1\KycController;
use App\Http\Controllers\Api\V1\MetaController;
use App\Http\Controllers\Api\V1\ProfileController;
use App\Http\Controllers\Api\V1\StaffController;
use Illuminate\Support\Facades\Route;

/*
| Portal API (/api/v1). Portal users authenticate with Sanctum Bearer tokens.
| Merchant API keys (np_live_… with HMAC) arrive in P1 on their own guard.
*/
Route::prefix('v1')->name('v1.')->group(function () {
    Route::get('meta', MetaController::class)->name('meta');

    // ── Authentication ───────────────────────────────────────────────
    Route::prefix('auth')->name('auth.')->group(function () {
        Route::middleware('throttle:auth')->group(function () {
            Route::post('register', [PasswordAuthController::class, 'register'])->name('register');
            Route::post('login', [PasswordAuthController::class, 'login'])->name('login');
            Route::post('forgot-password', [PasswordResetController::class, 'forgot'])->name('password.forgot');
            Route::post('reset-password', [PasswordResetController::class, 'reset'])->name('password.reset');
            Route::post('two-factor', [OtpAuthController::class, 'twoFactor'])->name('two-factor');
            Route::post('otp/verify', [OtpAuthController::class, 'verify'])->name('otp.verify');
            Route::post('oauth/exchange', [SocialAuthController::class, 'exchange'])->name('oauth.exchange');
        });

        Route::post('otp/request', [OtpAuthController::class, 'request'])->middleware('throttle:otp')->name('otp.request');

        Route::get('oauth/{provider}/redirect', [SocialAuthController::class, 'redirect'])
            ->whereIn('provider', ['google', 'github'])->name('oauth.redirect');
        Route::get('oauth/{provider}/callback', [SocialAuthController::class, 'callback'])
            ->whereIn('provider', ['google', 'github'])->name('oauth.callback');

        Route::get('email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
            ->middleware(['signed', 'throttle:6,1'])->name('verification.verify');

        Route::middleware('auth:sanctum')->group(function () {
            Route::post('logout', [PasswordAuthController::class, 'logout'])->name('logout');
            Route::post('logout-all', [PasswordAuthController::class, 'logoutEverywhere'])->name('logout-all');
            Route::post('email/verification-notification', [EmailVerificationController::class, 'resend'])
                ->middleware('throttle:6,1')->name('verification.send');
            Route::post('oauth/{provider}/link', [SocialAuthController::class, 'linkUrl'])
                ->whereIn('provider', ['google', 'github'])->name('oauth.link');
        });
    });

    // Public invitation preview (the invite page shows it before sign-in).
    Route::get('invitations/{token}', [StaffController::class, 'preview'])->middleware('throttle:30,1')->name('invitations.preview');

    Route::middleware('auth:sanctum')->group(function () {
        // ── Profile ──────────────────────────────────────────────────
        Route::prefix('me')->name('me.')->controller(ProfileController::class)->group(function () {
            Route::get('/', 'show')->name('show');
            Route::patch('/', 'update')->name('update');
            Route::put('password', 'password')->name('password');
            Route::post('phone', 'requestPhone')->middleware('throttle:otp')->name('phone.request');
            Route::post('phone/verify', 'verifyPhone')->middleware('throttle:auth')->name('phone.verify');
            Route::put('two-factor', 'twoFactor')->name('two-factor');
            Route::delete('social/{provider}', 'unlink')->whereIn('provider', ['google', 'github'])->name('social.unlink');
            Route::get('sessions', 'sessions')->name('sessions');
            Route::delete('sessions/{token}', 'revokeSession')->whereNumber('token')->name('sessions.revoke');
        });

        Route::post('invitations/{token}/accept', [StaffController::class, 'accept'])->name('invitations.accept');

        // ── Businesses ───────────────────────────────────────────────
        Route::get('businesses', [BusinessController::class, 'index'])->name('businesses.index');
        Route::post('businesses', [BusinessController::class, 'store'])->middleware('throttle:10,60')->name('businesses.store');

        Route::prefix('businesses/{business}')->name('businesses.')->scopeBindings()->group(function () {
            Route::get('/', [BusinessController::class, 'show'])->name('show');
            Route::patch('/', [BusinessController::class, 'update'])->name('update');
            Route::post('logo', [BusinessController::class, 'logo'])->name('logo');

            Route::get('kyc', [KycController::class, 'overview'])->name('kyc');
            Route::post('kyc/documents', [KycController::class, 'upload'])->name('kyc.documents.store');
            Route::get('kyc/documents/{document}', [KycController::class, 'download'])->name('kyc.documents.download');
            Route::delete('kyc/documents/{document}', [KycController::class, 'destroy'])->name('kyc.documents.destroy');
            Route::post('kyc/submit', [KycController::class, 'submit'])->middleware('two-factor')->name('kyc.submit');
            Route::post('sender-id', [KycController::class, 'requestSenderId'])->name('sender-id');

            Route::get('staff', [StaffController::class, 'index'])->name('staff.index');
            Route::patch('staff/{member}', [StaffController::class, 'update'])->name('staff.update');
            Route::delete('staff/{member}', [StaffController::class, 'destroy'])->name('staff.destroy');
            Route::get('invitations', [StaffController::class, 'invitations'])->name('invitations.index');
            Route::post('invitations', [StaffController::class, 'invite'])->name('invitations.store');
            Route::delete('invitations/{invitation}', [StaffController::class, 'revoke'])->name('invitations.revoke');

            Route::get('audit-logs', BusinessAuditController::class)->name('audit');
        });

        // ── NGUZO back-office ────────────────────────────────────────
        Route::prefix('admin')->name('admin.')->middleware('platform.admin')->group(function () {
            Route::get('dashboard', Admin\DashboardController::class)->name('dashboard');
            Route::get('businesses', [Admin\BusinessReviewController::class, 'index'])->name('businesses.index');

            Route::prefix('businesses/{business}')->name('businesses.')->scopeBindings()
                ->controller(Admin\BusinessReviewController::class)->group(function () {
                    Route::get('/', 'show')->name('show');
                    Route::get('documents/{document}', 'downloadDocument')->name('documents.download');

                    // Decisions need two-step verification (plan §7).
                    Route::middleware('two-factor')->group(function () {
                        Route::post('recommend', 'recommend')->name('recommend');
                        Route::post('decide', 'decide')->name('decide');
                        Route::post('suspend', 'suspend')->name('suspend');
                        Route::post('reinstate', 'reinstate')->name('reinstate');
                        Route::patch('documents/{document}', 'reviewDocument')->name('documents.review');
                        Route::patch('sender-ids/{senderId}', 'senderIdStatus')->name('sender-ids.status');
                    });
                });

            Route::get('users', [Admin\UserController::class, 'index'])->name('users.index');
            Route::get('users/{user}', [Admin\UserController::class, 'show'])->name('users.show');
            Route::get('audit-logs', Admin\AuditLogController::class)->name('audit');
        });
    });
});
