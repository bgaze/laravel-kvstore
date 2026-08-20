<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table) {
            // Bounded to 191 characters: a primary key on a longer utf8mb4
            // column exceeds the index size limit of older MySQL versions.
            $table->string('key', 191)->primary();
            $table->string('type')->nullable();
            $table->text('value')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    /**
     * Get the name of the table holding the store.
     */
    private function table(): string
    {
        $table = Config::get('kvstore.table');

        return is_string($table) && $table !== '' ? $table : 'kvstore';
    }
};
