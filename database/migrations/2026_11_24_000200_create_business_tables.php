<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A business is a NguzoPay merchant (tenant). DEVELOPMENT_PLAN calls this "merchants".
        Schema::create('businesses', function (Blueprint $table) {
            $table->ulid('id')->primary();
            // 4-digit merchant code used in control numbers: NP + code + sequence + check digit.
            $table->string('code', 4)->unique();
            // Aggregators (e.g. OnFika) own sub-businesses.
            $table->foreignUlid('parent_id')->nullable()->constrained('businesses')->nullOnDelete();
            $table->string('type', 20);
            $table->string('legal_name');
            $table->string('trading_name')->nullable();
            $table->string('registration_authority', 20)->nullable();
            $table->string('registration_number')->nullable();
            $table->string('tin', 9)->nullable();
            $table->string('vrn')->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 12)->nullable();
            $table->string('website')->nullable();
            $table->string('region')->nullable();
            $table->string('district')->nullable();
            $table->string('physical_address')->nullable();
            $table->string('postal_address')->nullable();
            $table->string('logo_path')->nullable();
            $table->string('status', 20)->default('draft')->index();
            $table->timestamp('submitted_at')->nullable();
            // Maker-checker: one admin recommends, a different admin decides.
            $table->foreignUlid('recommended_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('recommendation', 10)->nullable();
            $table->text('recommendation_notes')->nullable();
            $table->timestamp('recommended_at')->nullable();
            $table->foreignUlid('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        // Staff: a user's role in a business.
        Schema::create('business_members', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('business_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 20);
            $table->string('status', 20)->default('active');
            $table->string('job_title')->nullable();
            $table->foreignUlid('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['business_id', 'user_id']);
        });

        Schema::create('staff_invitations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('business_id')->constrained()->cascadeOnDelete();
            $table->string('email')->nullable();
            $table->string('phone', 12)->nullable();
            $table->string('role', 20);
            $table->string('job_title')->nullable();
            $table->string('token_hash', 64)->unique();
            $table->foreignUlid('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->foreignUlid('accepted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        // KYC / KYB documents, stored on a private disk.
        Schema::create('business_documents', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('business_id')->constrained()->cascadeOnDelete();
            $table->string('type', 40);
            $table->string('disk', 20);
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type', 100);
            $table->unsignedInteger('size');
            $table->string('status', 20)->default('pending');
            $table->text('review_notes')->nullable();
            $table->foreignUlid('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });

        // Branded SMS sender ID (e.g. GENESIS), registered through DarSMS.
        Schema::create('sms_sender_ids', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('business_id')->constrained()->cascadeOnDelete();
            $table->string('sender_id', 11)->unique();
            $table->string('status', 20)->default('requested');
            $table->text('rejection_reason')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_sender_ids');
        Schema::dropIfExists('business_documents');
        Schema::dropIfExists('staff_invitations');
        Schema::dropIfExists('business_members');
        Schema::dropIfExists('businesses');
    }
};
