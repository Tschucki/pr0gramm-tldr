<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->text('message');
            $table->fullText('message');
            $table->text('tldrValue')->nullable();
            $table->fullText('tldrValue');
            $table->string('type');
            $table->integer('messageId');
            $table->integer('itemId');
            $table->string('thumb')->nullable();
            $table->smallInteger('flags');
            $table->string('name');
            $table->smallInteger('mark');
            $table->integer('senderId');
            $table->integer('score');

            $table->boolean('read');
            $table->boolean('blocked');
            $table->boolean('repliedToComment')->default(false);
            $table->integer('replyCommentId')->nullable();
            $table->timestamp('created');
            $table->timestamps();

            $table->index('messageId');
            $table->index('itemId');
            $table->index('name');
            $table->index('senderId');
            $table->index('replyCommentId');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
