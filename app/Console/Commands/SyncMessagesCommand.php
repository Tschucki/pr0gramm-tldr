<?php

namespace App\Console\Commands;

use App\Models\Message;
use Illuminate\Console\Command;
use Symfony\Component\Console\Command\Command as CommandAlias;
use Tschucki\Pr0grammApi\Facades\Pr0grammApi;

class SyncMessagesCommand extends Command
{
    protected $signature = 'pr0:sync-messages';

    protected $description = 'Sync messages from pr0gramm.com inbox';

    public function handle(): int
    {
        try {
            $this->info('Checking if logged in');
            if (! Pr0grammApi::loggedIn()['loggedIn']) {
                $this->error('Could not login!');

                return CommandAlias::FAILURE;
            }

            $this->info('Logged in!');
            $this->info('Fetching Messages');

            $comments = Pr0grammApi::Inbox()->comments()['messages'];

            $this->info('Syncing Comments');

            $this->syncComments($comments);

        } catch (\Throwable $e) {
            $this->error('Error: '.$e->getMessage());

            return CommandAlias::FAILURE;
        }

        $this->info("\nDone!");

        return CommandAlias::SUCCESS;
    }

    protected function syncComments(array $comments): void
    {
        $this->withProgressBar($comments, function ($comment) {
            if ($this->isValidComment($comment)) {
                Message::firstOrCreate(['messageId' => $comment['id']], $comment);
            }
        });
    }

    protected function isValidComment(array $comment): bool
    {
        return $comment['name'] !== 'TLDR'
            && ! $comment['blocked']
            && $this->commentMatchesPattern($comment['message'])
            && Message::where('messageId', $comment['id'])->where('repliedToComment', true)->doesntExist();
    }

    protected function commentMatchesPattern(string $comment): bool
    {
        return $this->commentStartsWithPattern($comment) || $this->commentEndsWithPattern($comment);
    }

    protected function commentStartsWithPattern(string $comment): bool
    {
        return (bool) preg_match('/^@tldr/i', $comment);
    }

    protected function commentEndsWithPattern(string $comment): bool
    {
        return (bool) preg_match('/^.{200,}@tldr$/i', $comment);
    }
}
