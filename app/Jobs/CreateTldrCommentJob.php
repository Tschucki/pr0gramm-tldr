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

    private string $basePrompt = 'Bitte fasse den folgenden Kommentar in maximal 100 Worten zusammen (TLDR). Bleibe im gleichen Schreibstil und behalte den Ton des ursprünglichen Kommentars bei. Halte dich aber weiterhin an deine ethischen Richtlinien. Übersetze bei Bedarf den Kommentar ins Deutsche. Lasse Einleitungen wie "Der Kommentar beschreibt..." weg und komme bitte direkt zur Zusammenfassung: ';

    private string $notLongEnoughText = 'Der Kommentar ist nicht lang genug. Den Text kannst du selbst zusammenfassen.';

    public function uniqueId(): string
    {
        return (string)$this->message->messageId;
    }

    public function __construct(Message $message)
    {
        $this->message = $message;
    }

    public function handle(): void
    {
        try {
            $postInfo = Pr0grammApi::Post()->info($this->message->itemId);

            /** @var array[] $comments */
            $comments = $postInfo['comments'];

            $comment = collect($comments)->firstWhere('id', $this->message->messageId);

            if (!$comment) {
                return;
            }

            $parentId = $comment['parent'];

            $repliedToComment = collect($comments)->firstWhere('id', $parentId);

            if ($this->commentIsMine($repliedToComment)) {
                return;
            }

            if ($this->commentIsLongEnough($repliedToComment['content'])) {

                $tldrValue = "TLDR: \n" . $this->getTldrValue($repliedToComment['content']);

            } else {
                $tldrValue = $this->notLongEnoughText;
            }
            /** @var array[] $addCommentResponse */
            $addCommentResponse = Pr0grammApi::Comment()->add($this->message->itemId, $tldrValue, $this->message->messageId);

            // Check if Comment was added successfully
            $commentExists = collect($addCommentResponse['comments'])->firstWhere('id', $addCommentResponse['commentId']);

            // Comment exists on item
            if ($commentExists) {
                $this->message->update(['repliedToComment' => true, 'tldrValue' => $tldrValue, 'replyCommentId' => $addCommentResponse['commentId']]);
            }
        } catch (RequestException $e) {
            if ($e->response->failed() && $e->response->status() == 429) {
                // Rate Limit Reached. Release the Job in 30 seconds back into the queue
                $this->release(30);
            }
        }
    }

    protected function getTldrValue(string $comment): ?string
    {
        $client = OpenAI::client(config('services.openai.api_key'));

        $response = $client->chat()->create([
            'model' => 'gpt-3.5-turbo',
            'messages' => [
                ['role' => 'user', 'content' => $this->basePrompt . $comment],
            ],
        ]);

        if ($response->choices[0]->finishReason === 'stop') {
            return $this->sanitizeContent($response->choices[0]->message->content);
        } else {
            $this->fail('Could not create TLDR comment. No stop finish reason.');
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
        return $comment['name'] == config('services.pr0gramm.username', 'TLDR');
    }
}
