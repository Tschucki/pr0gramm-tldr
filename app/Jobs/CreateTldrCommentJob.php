<?php

namespace App\Jobs;

use App\Models\Message;
use GuzzleHttp\Client;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use OpenAI;
use thiagoalessio\TesseractOCR\TesseractOCR;
use Tschucki\Pr0grammApi\Facades\Pr0grammApi;

class CreateTldrCommentJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public Message $message;

    protected string $basePrompt;

    protected array $notLongEnoughTexts = [
        'Der Kommentar ist nicht lang genug. Den Text kannst du selbst zusammenfassen.',
        'Der Kommentar ist nicht lang genug. Den Text kannst du selbst zusammenfassen. Das schaffst sogar du.',
        'Der Kommentar ist nicht lang genug. Fettbauch.',
        'Der Kommentar ist nicht lang genug. Wackelwampe.',
        'Der Kommentar ist nicht lang genug. Genau so wie dein Pimmel.',
        'Der Kommentar ist nicht lang genug. Penner.',
        'Der Kommentar ist nicht lang genug. Du Lutscher',
        'Der Kommentar ist nicht lang genug. Ich konnte zwischen den Zeilen aber lesen, dass es um deine Mama ging.',
        'Etzala reichts. Getrollt wird ned.',
        'TLDR: https://www.youtube.com/watch?v=8ybW48rKBME',
        'TLDR: https://youtu.be/fPaDlNPbDSM?si=oHa7R_hryFNY4kbY&t=114',
        'Den Text kannst du ja wohl selber lesen. Du hast auch nur einen Kopf, damit es dir nicht in den Hals regnet.',
        'Trottel, der Kommentar ist nicht lang genug. Du kannst das besser.',
    ];

    protected bool $isFunComment = false;

    protected array $notLongEnoughPersonalTexts = [
        'Da steht der Schniedel von $name ist winzig. Kann das jemand bestätigen?',
        'TLDR: $name hat einen kleinen Schnipi. Steht hier schwarz auf weiß.',
        'TLDR: $name braucht eine Lupe, um seinen eigenen Pimmel zu finden.',
        'TLDR: $name hat einen Männerbusen. Aber der steht dir gut.',
        'TLDR: $name ist hässlich.',
        'TLDR: Alle hassen $name.',
        'TLDR: $name hat gerade so viel IQ zum Atmen.',
        'TLDR: $name ist ein Lappen.',
        '$name kann nicht lesen.',
    ];

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

            // check if the comment has a parent
            if ($comment['parent'] === 0 && $this->commentOnlyContainsMention($this->message->message)) {
                $this->summarizePost();
            } else {
                /**
                 * @var array $commentToSummarize
                 * */
                $commentToSummarize = $this->getCommentToSummarize($comment);

                if (! $commentToSummarize || $this->commentIsMine($commentToSummarize)) {
                    return;
                }

                $tldrValue = null;

                if ($this->commentIsLongEnough($commentToSummarize['content'])) {
                    $summary = $this->getTldrValue($commentToSummarize['content']);
                    if ($summary !== null && $summary !== '' && Str::wordCount($summary) > 5) {
                        $tldrValue = "TLDR: \n".$summary;
                    }
                } elseif (random_int(0, 2) === 0) {
                    $tldrValue = $this->notLongEnoughTexts[array_rand($this->notLongEnoughTexts)];
                    $this->isFunComment = true;
                } else {
                    $tldrValue = $this->getPersonalizedNotLongEnoughText($this->message['name']);
                    $this->isFunComment = true;
                }

                if ($tldrValue !== null && $tldrValue !== '') {

                    // Check if tldrValue is identical to another summary on the same post
                    $otherTldrComments = Message::where('itemId', $this->message->itemId)
                        ->where('messageId', '!=', $this->message->messageId)
                        ->where('tldrValue', $tldrValue)
                        ->first();

                    if ($otherTldrComments !== null) {
                        $tldrValue = ' https://pr0gramm.com/new/'.$this->message->itemId.':comment'.$otherTldrComments->messageId;
                    }

                    $this->addTldrComment($tldrValue);
                }
            }

        } catch (RequestException $e) {
            if ($e->response->failed() && $e->response->status() == 429) {
                // Rate Limit Reached. Release the Job in 30 seconds back into the queue
                $this->release(30);
            }
        }
    }

    protected function commentOnlyContainsMention(string $comment): bool
    {
        $comment = trim($comment);

        return Str::lower($comment) === '@tldr';
    }

    protected function getPersonalizedNotLongEnoughText(string $name): string
    {
        $randomText = $this->notLongEnoughPersonalTexts[array_rand($this->notLongEnoughPersonalTexts)];

        return Str::replace('$name', $name, $randomText);
    }

    protected function summarizePost(): void
    {
        $image = $this->message->image;
        $imageUrl = 'https://img.pr0gramm.com/'.$image;
        $imageText = $this->getImageText($imageUrl);
        if ($imageText && Str::wordCount($imageText) >= 40) {
            $tldrValue = $this->getTldrValue($imageText);
            if ($tldrValue !== null && $tldrValue !== '' && Str::wordCount($tldrValue) > 5) {
                $this->addTldrComment("TLDR: \n".$tldrValue);
            }
        }
    }

    protected function getImageText(string $imageUrl): ?string
    {
        try {
            $client = new Client;
            $response = $client->get($imageUrl);
            $image = $response->getBody()->getContents();
            $fileNameFragments = explode('/', $this->message->image);
            $fileName = end($fileNameFragments);

            if (! Str::endsWith($fileName, ['.jpg', '.jpeg', '.png', '.tiff'])) {
                if ($this->message->subtitle) {
                    $response = $client->get('https://pr0gramm.com/data/images/'.$this->message->subtitle);
                    if ($response->getStatusCode() === 200) {
                        return $response->getBody()->getContents();
                    }
                }

                return null;
            }

            Storage::put($fileName, $image);

            return (new TesseractOCR(Storage::path($fileName)))
                ->run();
        } catch (\Throwable $e) {
            $this->release(30);
        }

        return null;
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

        Pr0grammApi::Comment()->fav($addCommentResponse['commentId']);

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
                'model' => $this->getGptModel(),
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

    protected function getGptModel(): string
    {
        return 'gpt-4o-mini';
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
        $endsWithPattern = '/^.{200,}.*@tldr\s*$/is';

        return (bool) preg_match($endsWithPattern, $comment);
    }
}
