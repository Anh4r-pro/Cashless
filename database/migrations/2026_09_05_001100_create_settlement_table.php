<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('settlement', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained('tenant');
            $t->bigInteger('jumlah');
            $t->enum('metode', ['cash', 'transfer']);
            $t->string('bukti')->nullable();
            $t->string('keterangan', 300)->default('');
            $t->string('operator')->default('');
            $t->char('created_at', 24)->nullable();
            $t->index('tenant_id');
        });
    }

    public function down(): void { Schema::dropIfExists('settlement'); }
};
