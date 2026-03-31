<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations for all tables of vruta.
     *
     * @return void
     */
    public function up()
    {
        // Schema::create('account_group_users', function (Blueprint $table) { $table->id(); $table->timestamps(); });
        // Schema::create('account_groups', function (Blueprint $table) { $table->id(); $table->timestamps(); });
        // Schema::create('accounts', function (Blueprint $table) { $table->id(); $table->timestamps(); });
        // Schema::create('accounts_messages', function (Blueprint $table) { $table->id(); $table->timestamps(); });
        // Schema::create('customers', function (Blueprint $table) { $table->id(); $table->timestamps(); });
        // Schema::create('devices_activity', function (Blueprint $table) { $table->id(); $table->timestamps(); });
        // Schema::create('inventory', function (Blueprint $table) { $table->id(); $table->timestamps(); });
        // Schema::create('inventory_returns', function (Blueprint $table) { $table->id(); $table->timestamps(); });
        // Schema::create('payments', function (Blueprint $table) { $table->id(); $table->timestamps(); });
        // Schema::create('price_lists', function (Blueprint $table) { $table->id(); $table->timestamps(); });
        // Schema::create('prod_categories', function (Blueprint $table) { $table->id(); $table->timestamps(); });
        // Schema::create('products', function (Blueprint $table) { $table->id(); $table->timestamps(); });
        // Schema::create('sales', function (Blueprint $table) { $table->id(); $table->timestamps(); });
        // Schema::create('sincronizaciones', function (Blueprint $table) { $table->id(); $table->timestamps(); });
        // Schema::create('warehouses', function (Blueprint $table) { $table->id(); $table->timestamps(); });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('account_group_users');
        Schema::dropIfExists('account_groups');
        Schema::dropIfExists('accounts');
        Schema::dropIfExists('accounts_messages');
        Schema::dropIfExists('customers');
        Schema::dropIfExists('devices_activity');
        Schema::dropIfExists('inventory');
        Schema::dropIfExists('inventory_returns');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('price_lists');
        Schema::dropIfExists('prod_categories');
        Schema::dropIfExists('products');
        Schema::dropIfExists('sales');
        Schema::dropIfExists('sincronizaciones');
        Schema::dropIfExists('warehouses');
    }
};
