<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('billing_profiles', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('company_id', 26)->unique();

            // Stripe linkage. customer id is set the first time checkout runs;
            // subscription fields track the current (single) Atlas subscription.
            $table->string('stripe_customer_id')->nullable()->unique();
            $table->string('stripe_subscription_id')->nullable()->index();
            $table->string('subscription_status')->nullable();
            $table->string('price_id')->nullable();
            $table->timestamp('current_period_ends_at')->nullable();
            $table->boolean('cancel_at_period_end')->default(false);

            // Beta-safe manual grant — an operator can keep a company running
            // regardless of Stripe state. Read by the CM-78 access gate.
            $table->boolean('beta_access_override')->default(false);

            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_profiles');
    }
};
