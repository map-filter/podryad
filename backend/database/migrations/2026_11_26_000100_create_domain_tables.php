<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('short_name')->nullable();
            $t->string('bin', 12)->unique();
            $t->string('city')->nullable();
            $t->string('address')->nullable();
            $t->string('director')->nullable();
            $t->string('phone')->nullable();
            $t->string('email')->nullable();
            $t->string('notify_email')->nullable();
            $t->boolean('notify_email_enabled')->default(true);
            $t->string('telegram_chat_id')->nullable();
            $t->boolean('notify_telegram_enabled')->default(true);
            $t->json('notify_thresholds')->nullable();
            $t->timestamps();
        });

        Schema::create('customers', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('short_name');
            $t->string('bin', 12)->nullable();
            $t->string('city')->nullable();
            $t->string('industry')->nullable();
            $t->string('contact')->nullable();
            $t->timestamps();
        });

        Schema::create('facilities', function (Blueprint $t) {
            $t->id();
            $t->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $t->string('name');
            $t->string('address')->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();
        });

        Schema::table('users', function (Blueprint $t) {
            $t->foreignId('organization_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $t->foreignId('customer_id')->nullable()->after('organization_id')->constrained()->nullOnDelete();
            $t->string('role', 20)->default('head')->after('password');
            $t->string('position')->nullable()->after('role');
            $t->string('phone')->nullable()->after('position');
        });

        Schema::create('document_types', function (Blueprint $t) {
            $t->id();
            $t->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $t->string('code', 40);
            $t->string('name');
            $t->string('short_name', 60);
            $t->string('category', 30)->default('ot');
            $t->unsignedSmallInteger('validity_months')->nullable();
            $t->boolean('has_group')->default(false);
            $t->boolean('is_basic')->default(false);
            $t->text('description')->nullable();
            $t->unsignedSmallInteger('sort')->default(100);
            $t->timestamps();
            $t->unique(['organization_id', 'code']);
        });

        Schema::create('workers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $t->string('last_name');
            $t->string('first_name');
            $t->string('middle_name')->nullable();
            $t->string('iin', 12);
            $t->date('birth_date')->nullable();
            $t->string('position');
            $t->unsignedTinyInteger('grade')->nullable();
            $t->string('site')->nullable();
            $t->string('phone')->nullable();
            $t->string('photo_path')->nullable();
            $t->date('hired_at')->nullable();
            $t->boolean('is_active')->default(true);
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->unique(['organization_id', 'iin']);
            $t->index(['organization_id', 'last_name']);
        });

        Schema::create('worker_documents', function (Blueprint $t) {
            $t->id();
            $t->foreignId('worker_id')->constrained()->cascadeOnDelete();
            $t->foreignId('document_type_id')->constrained()->restrictOnDelete();
            $t->string('number')->nullable();
            $t->date('issued_at')->nullable();
            $t->date('valid_until')->nullable();
            $t->string('issuer')->nullable();
            $t->unsignedTinyInteger('group_level')->nullable();
            $t->string('scan_path')->nullable();
            $t->string('scan_name')->nullable();
            $t->string('scan_mime', 80)->nullable();
            $t->unsignedInteger('scan_size')->nullable();
            $t->smallInteger('notified_threshold')->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->index(['worker_id', 'document_type_id']);
            $t->index('valid_until');
        });

        Schema::create('requirement_profiles', function (Blueprint $t) {
            $t->id();
            $t->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $t->string('name');
            $t->string('work_type');
            $t->text('description')->nullable();
            $t->timestamps();
        });

        Schema::create('requirement_profile_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('requirement_profile_id')->constrained()->cascadeOnDelete();
            $t->foreignId('document_type_id')->constrained()->cascadeOnDelete();
            $t->unsignedTinyInteger('min_group')->nullable();
            $t->unique(['requirement_profile_id', 'document_type_id']);
        });

        Schema::create('admission_requests', function (Blueprint $t) {
            $t->id();
            $t->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $t->string('number', 30);
            $t->foreignId('customer_id')->constrained()->restrictOnDelete();
            $t->foreignId('facility_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('requirement_profile_id')->constrained()->restrictOnDelete();
            $t->string('work_type');
            $t->text('work_description')->nullable();
            $t->date('start_date');
            $t->date('end_date');
            $t->string('status', 20)->default('draft');
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('signed_at')->nullable();
            $t->foreignId('signed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->longText('signature')->nullable();
            $t->string('signed_hash', 64)->nullable();
            $t->string('sign_mode', 20)->nullable();
            $t->string('signer_subject')->nullable();
            $t->timestamp('submitted_at')->nullable();
            $t->timestamp('reviewed_at')->nullable();
            $t->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->text('review_comment')->nullable();
            $t->timestamps();
            $t->unique(['organization_id', 'number']);
            $t->index(['customer_id', 'status']);
        });

        Schema::create('admission_request_workers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('admission_request_id')->constrained()->cascadeOnDelete();
            $t->foreignId('worker_id')->constrained()->cascadeOnDelete();
            $t->string('customer_decision', 20)->nullable();
            $t->text('customer_comment')->nullable();
            $t->unique(['admission_request_id', 'worker_id']);
        });

        Schema::create('notification_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $t->string('channel', 20);
            $t->string('recipient')->nullable();
            $t->string('subject');
            $t->text('body');
            $t->json('items')->nullable();
            $t->string('status', 20);
            $t->text('error')->nullable();
            $t->timestamp('sent_at')->nullable();
            $t->timestamps();
            $t->index(['organization_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_logs');
        Schema::dropIfExists('admission_request_workers');
        Schema::dropIfExists('admission_requests');
        Schema::dropIfExists('requirement_profile_items');
        Schema::dropIfExists('requirement_profiles');
        Schema::dropIfExists('worker_documents');
        Schema::dropIfExists('workers');
        Schema::dropIfExists('document_types');
        Schema::table('users', function (Blueprint $t) {
            $t->dropConstrainedForeignId('organization_id');
            $t->dropConstrainedForeignId('customer_id');
            $t->dropColumn(['role', 'position', 'phone']);
        });
        Schema::dropIfExists('facilities');
        Schema::dropIfExists('customers');
        Schema::dropIfExists('organizations');
    }
};
