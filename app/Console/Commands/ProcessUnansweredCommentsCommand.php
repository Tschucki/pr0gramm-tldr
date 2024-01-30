<?php

namespace App\Console\Commands;

use App\Jobs\CreateTldrCommentJob;
use App\Models\Message;
use Illuminate\Console\Command;

class ProcessUnansweredCommentsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'pr0:process-old-comments';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Tries to process old comments that were not answered yet.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Processing old comments...');

        $comments = Message::where('tldrValue', null)->where('created_at', '>', now()->subDays(3))->get();

        $this->info('Found ' . count($comments) . ' comments.');
        $this->info('Dispatching jobs...');
        $this->withProgressBar($comments, function (Message $comment) {
            CreateTldrCommentJob::dispatch($comment);
        });

        $this->info("\nDone!");

        return Command::SUCCESS;
    }
}
