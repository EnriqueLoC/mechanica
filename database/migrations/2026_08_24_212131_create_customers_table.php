<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workshop_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('name');
            $table->string('phone', 20);
            $table->string('email')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->unique(['workshop_id', 'phone']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
