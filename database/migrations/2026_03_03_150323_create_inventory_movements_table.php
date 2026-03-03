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
        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->id(); // PK
            
            // Kapcsolat a készlet sorhoz
            $table->foreignId('stock_id')->constrained('stocks')->restrictOnDelete();
            
            // Milyen típusú mozgás történt?
            $table->enum('type', ['purchase', 'sale', 'transfer_in', 'transfer_out', 'adjustment']);
            
            // Mennyiség (lehet pozitív vagy negatív is, ezért sima integer)
            $table->integer('quantity'); 
            
            // Opcionális hivatkozás (pl. melyik rendelés ID-ja miatt történt)
            $table->unsignedBigInteger('reference_id')->nullable();
            
            // Melyik felhasználó csinálta? (A Laravel alapértelmezett users táblájához kötjük)
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            
            $table->timestamps(); // Ez automatikusan rögzíti, hogy MIKOR történt
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventory_movements');
    }
};
