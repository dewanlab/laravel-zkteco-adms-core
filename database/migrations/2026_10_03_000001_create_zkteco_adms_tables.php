<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Get the prefixed table name.
     */
    private function tableName(string $name): string
    {
        $prefix = config('zkteco-adms-core.table_prefix', 'zkteco_');

        return $prefix.$name;
    }

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Devices Table
        Schema::create($this->tableName('devices'), function (Blueprint $table) {
            $table->id();
            $table->string('serial_number', 64)->unique();
            $table->string('name')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('status', 20)->default('online');
            $table->timestamp('last_activity_at')->nullable();
            $table->string('device_type')->nullable();
            $table->string('push_version')->nullable();
            $table->string('firmware_version')->nullable();
            $table->string('timezone')->default('UTC');
            $table->json('options')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('last_activity_at');
        });

        // 2. Users Table
        Schema::create($this->tableName('users'), function (Blueprint $table) {
            $table->id();
            $table->string('pin', 32)->unique();
            $table->string('name')->nullable();
            $table->string('card_number', 64)->nullable();
            $table->string('password')->nullable();
            $table->unsignedSmallInteger('privilege')->default(0); // 0=User, 14=SuperAdmin
            $table->unsignedInteger('group_id')->default(1);
            $table->unsignedInteger('timezone_id')->default(0);
            $table->timestamps();

            $table->index('card_number');
        });

        // 3. Attendance Logs Table
        Schema::create($this->tableName('attendance_logs'), function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->nullable()->constrained($this->tableName('devices'))->nullOnDelete();
            $table->string('pin', 32);
            $table->dateTime('recorded_at');
            $table->dateTime('occurred_at')->nullable();
            $table->unsignedSmallInteger('status')->default(0);
            $table->unsignedSmallInteger('verify_mode')->default(0);
            $table->string('work_code', 32)->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamps();

            // Strict unique index for idempotent deduplication
            $table->unique(['device_id', 'pin', 'recorded_at'], 'zk_att_unique_punch');
            $table->index('pin');
            $table->index('recorded_at');
            $table->index('status');
        });

        // 4. Multimodal Biodata Table (Face, Fingerprint, Palm)
        Schema::create($this->tableName('biodata'), function (Blueprint $table) {
            $table->id();
            $table->string('pin', 32);
            $table->unsignedSmallInteger('bio_type')->default(1); // 1=FP, 9=Face, 25=Palm
            $table->unsignedSmallInteger('template_index')->default(0);
            $table->unsignedInteger('size')->default(0);
            $table->boolean('is_valid')->default(true);
            $table->unsignedSmallInteger('duress')->default(0);
            $table->longText('template');
            $table->timestamps();

            $table->unique(['pin', 'bio_type', 'template_index'], 'zk_bio_unique');
            $table->index(['pin', 'bio_type']);
        });

        // 5. Access Control Authorizations Table
        Schema::create($this->tableName('user_authorizations'), function (Blueprint $table) {
            $table->id();
            $table->string('pin', 32);
            $table->unsignedInteger('door_id')->default(1);
            $table->unsignedInteger('timezone_id')->default(1);
            $table->timestamps();

            $table->unique(['pin', 'door_id', 'timezone_id'], 'zk_auth_unique');
            $table->index('pin');
        });

        // 6. Device Outbound Commands Queue Table
        Schema::create($this->tableName('device_commands'), function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained($this->tableName('devices'))->cascadeOnDelete();
            $table->unsignedBigInteger('command_id')->default(0);
            $table->string('command_type', 32)->default('DATA');
            $table->text('command_string');
            $table->string('status', 20)->default('pending');
            $table->integer('return_code')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamps();

            $table->index(['device_id', 'status']);
            $table->index('command_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists($this->tableName('device_commands'));
        Schema::dropIfExists($this->tableName('user_authorizations'));
        Schema::dropIfExists($this->tableName('biodata'));
        Schema::dropIfExists($this->tableName('attendance_logs'));
        Schema::dropIfExists($this->tableName('users'));
        Schema::dropIfExists($this->tableName('devices'));
    }
};
