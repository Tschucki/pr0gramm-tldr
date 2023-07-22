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
            if (Pr0grammApi::loggedIn()['loggedIn']) {
                $this->info('Logged in!');
                $this->info('Fetching Messages');

                $comments = Pr0grammApi::Inbox()->comments()['messages'];

                $this->info('Syncing Comments');

                $this->withProgressBar($comments, function ($comment) {
                    // Comment Matches pattern has not been replied to
                    if ($comment['name'] != 'TLDR' &&
                        $comment['blocked'] == false &&
                        $this->commentMatchesPattern($comment['message']) &&
                        Message::where('messageId', $comment['id'])->where('repliedToComment', true)->doesntExist()) {
                        Message::firstOrCreate(['messageId' => $comment['id']], $comment);
                    }
                });

            } else {
                $this->error('Could not login!');

                return CommandAlias::FAILURE;
            }

        } catch (\Throwable $e) {
            $this->error('Error: '.$e->getMessage());

            return CommandAlias::FAILURE;
        }

        $this->info("\nDone!");

        return CommandAlias::SUCCESS;
    }

    protected function commentMatchesPattern(string $comment): bool
    {
        // Pattern: Comment starts with @tldr
        $pattern = '/^@tldr/i';

        return boolval(preg_match($pattern, $comment));
    }
}
