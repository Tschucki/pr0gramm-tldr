<?php

namespace App\Jobs;

use App\Models\Message;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use OpenAI;
use Tschucki\Pr0grammApi\Facades\Pr0grammApi;

class CreateTldrCommentJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public Message $message;

    private string $basePrompt;

    private string $notLongEnoughText = 'Der Kommentar ist nicht lang genug. Den Text kannst du selbst zusammenfassen.';

    public function uniqueId(): string
    {
        return (string) $this->message->messageId;
    }

    public function __construct(Message $message)
    {
        $this->message = $message;
        $this->basePrompt = config('tldr.prompt_template');
    }

    public function handle(): void
    {
        try {
            /** @var array[] $postInfo */
            $postInfo = Pr0grammApi::Post()->info($this->message->itemId);
            $comment = collect($postInfo['comments'])->firstWhere('id', $this->message->messageId);

            if (! $comment) {
                return;
            }
            /**
             * @var array $commentToSummarize
             * */
            $commentToSummarize = $this->getCommentToSummarize($comment);

            if (! $commentToSummarize || $this->commentIsMine($commentToSummarize)) {
                return;
            }

            if ($this->commentIsLongEnough($commentToSummarize['content'])) {
                $tldrValue = "TLDR: \n".$this->getTldrValue($commentToSummarize['content']);
            } else {
                $tldrValue = $this->notLongEnoughText;
            }

            if ($tldrValue !== null && $tldrValue !== '') {
                $this->addTldrComment($tldrValue);
            }

        } catch (RequestException $e) {
            if ($e->response->failed() && $e->response->status() == 429) {
                // Rate Limit Reached. Release the Job in 30 seconds back into the queue
                $this->release(30);
            }
        }
    }

    /**
     * @throws RequestException
     */
    protected function getCommentToSummarize(array $comment): ?array
    {
        if ($this->commentEndsWithPattern($this->message->message)) {
            return $comment;
        }

        $parentId = $comment['parent'];
        /** @var array[] $postInfo */
        $postInfo = Pr0grammApi::Post()->info($this->message->itemId);

        return collect($postInfo['comments'])->firstWhere('id', $parentId);
    }

    protected function addTldrComment(string $tldrValue): void
    {
        $addCommentResponse = Pr0grammApi::Comment()->add($this->message->itemId, $tldrValue, $this->message->messageId);

        /**
         * @var array $comments
         * */
        $comments = $addCommentResponse['comments'];

        $commentExists = collect($comments)->firstWhere('id', $addCommentResponse['commentId']);

        if ($commentExists) {
            $this->message->update([
                'repliedToComment' => true,
                'tldrValue' => $tldrValue,
                'replyCommentId' => $addCommentResponse['commentId'],
            ]);
        }
    }

    protected function getTldrValue(string $comment): ?string
    {
        try {
            $client = OpenAI::client(config('services.openai.api_key'));
            $response = $client->chat()->create([
                'model' => 'gpt-3.5-turbo',
                'messages' => [
                    ['role' => 'user', 'content' => $this->basePrompt.$comment],
                ],
            ]);

            if ($response->choices[0]->finishReason === 'stop') {
                return $this->sanitizeContent($response->choices[0]->message->content);
            } else {
                $this->fail('Could not create TLDR comment. No stop finish reason.');
            }
        } catch (OpenAI\Exceptions\ErrorException $errorException) {
            // Model is currently overloaded
            if ($errorException->getCode() === 503) {
                $this->release(30);
            } else {
                $this->fail($errorException->getMessage());
            }
        }

        return null;
    }

    protected function commentIsLongEnough(string $comment): bool
    {
        return Str::wordCount($comment) >= 40;
    }

    protected function sanitizeContent(string $content): string
    {
        return $this->replaceAtMentions($content);
    }

    protected function replaceAtMentions(string $content): string
    {
        $pattern = '/@([a-zA-Z0-9_]+)/';
        $replacement = '$1';

        return preg_replace($pattern, $replacement, $content);
    }

    protected function commentIsMine(array $comment): bool
    {
        return $comment['name'] === config('services.pr0gramm.username', 'TLDR');
    }

    protected function commentEndsWithPattern(string $comment): bool
    {
        // Pattern: Comments ends with @tldr but has at least 200 characters
        $endsWithPattern = '/^.{200,}@tldr$/i';

        return (bool) preg_match($endsWithPattern, $comment);
    }
}
