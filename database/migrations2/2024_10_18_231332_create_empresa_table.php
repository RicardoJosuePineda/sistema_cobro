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
        Schema::create('empresa', function (Blueprint $table) {
            $table->string('idEmpresa', 6)->primary();
            $table->string('nit', 14);
            $table->string('nombre', 50);
            $table->text('logo');
            $table->integer('estado');
            $table->string('idEmpleado', 6)->index('idempleado');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('empresa');
    }
};
