<?php

namespace App\Observers;

use App\Jobs\CreateTldrCommentJob;
use App\Models\Message;

class MessageObserver
{
    public bool $afterCommit = true;

    public function created(Message $message): void
    {
        CreateTldrCommentJob::dispatch($message);
    }
}
