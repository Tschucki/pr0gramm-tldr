<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Message extends Model
{
    protected $fillable = [
        'type',
        'messageId',
        'itemId',
        'thumb',
        'flags',
        'name',
        'mark',
        'senderId',
        'score',
        'created',
        'message',
        'read',
        'blocked',
        'repliedToComment',
        'tldrValue',
        'replyCommentId',
        'image'
    ];

    protected $casts = [
        'created' => 'datetime',
        'blocked' => 'boolean',
        'read' => 'boolean',
        'repliedToComment' => 'boolean',
    ];
}
