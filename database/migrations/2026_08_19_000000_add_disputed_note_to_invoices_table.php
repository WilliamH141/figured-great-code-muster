<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Lets an invoice be booked into the accounts while flagged as disputed -
// separate from entered_at, which just means "the numbers are recorded".
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->text('disputed_note')->nullable()->after('entered_at');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('disputed_note');
        });
    }
};
