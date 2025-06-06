<?php

namespace App\Console\Commands;

use App\Models\Message;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
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
            Log::error($e);

            return CommandAlias::FAILURE;
        }

        $this->info("\nDone!");

        return CommandAlias::SUCCESS;
    }

    protected function syncComments(array $comments): void
    {
        $this->withProgressBar($comments, function ($comment) {
            if ($this->isValidComment($comment)) {
                $posts = Pr0grammApi::Post()->get([
                    'id' => $comment['itemId'],
                    'flags' => 31,
                ]);

                $post = collect($posts->json()['items'])->filter(function (array $post) use ($comment) {
                    return $post['id'] === $comment['itemId'];
                });

                $image = $post->first()['image'] ?? null;

                $subtitles = isset($post->first()['subtitles']) ? $post->first()['subtitles'] : null;

                if ($image !== null) {
                    $comment['image'] = $image;
                }

                if ($subtitles !== null) {
                    $subtitle = null;

                    foreach ($subtitles as $sub) {
                        if (isset($sub['isDefault']) && $sub['isDefault']) {
                            $subtitle = $sub['path'];
                            break;
                        }
                    }

                    if ($subtitle === null) {
                        $subtitle = isset($subtitles[0]['path']) ? $subtitles[0]['path'] : null;
                    }

                    $comment['subtitle'] = $subtitle;
                }

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
        return (bool) preg_match('/^.{200,}.*@tldr\s*$/is', $comment);
    }
}
