<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credentialables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('credential_id')->constrained()->cascadeOnDelete();
            $table->morphs('credentialable'); // credentialable_id + credentialable_type
            $table->timestamps();
            $table->unique(
                ['credential_id', 'credentialable_id', 'credentialable_type'],
                'credentialable_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credentialables');
    }
};
