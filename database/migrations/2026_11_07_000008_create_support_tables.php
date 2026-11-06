<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** 客服工單（充值端）。玩家從玩家中心或充值網站登入後提出，管理員在後台回覆。 */
    public function up(): void
    {
        Schema::create('support_tickets', function (Blueprint $table) {
            $table->id();
            $table->string('ticket_no', 24)->unique();
            $table->foreignId('player_id')->constrained('players');
            $table->string('category', 16);
            $table->string('subject', 100);
            $table->string('order_no', 32)->nullable();
            $table->string('contact_email', 120)->nullable();
            $table->string('status', 16);           // open 待處理、answered 已回覆、closed 已結案
            $table->timestamp('last_message_at');
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'last_message_at']);
            $table->index(['player_id', 'id']);
        });

        Schema::create('support_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained('support_tickets');
            $table->string('author_type', 8);       // player、admin
            $table->foreignId('admin_user_id')->nullable()->constrained('admin_users');
            $table->text('body');
            $table->timestamp('created_at');
            $table->index(['ticket_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_messages');
        Schema::dropIfExists('support_tickets');
    }
};
