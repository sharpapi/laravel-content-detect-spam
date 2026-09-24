---
name: sharpapi-content-detect-spam
description: Classify text as spam with a score and reason using SharpAPI via `SharpAPI\ContentDetectSpam\ContentDetectSpamService` (sharpapi/laravel-content-detect-spam). Use when moderating comments, contact forms, reviews or messages for spam, or touching `detectSpam()`, `fetchResults()` or `config/sharpapi-content-detect-spam.php`.
---

# SharpAPI Content Detect Spam

`sharpapi/laravel-content-detect-spam` wraps one SharpAPI endpoint (`POST /content/detect_spam`) to classify text as spam or not, with a confidence score and a reason. The work is async: `detectSpam()` submits a job and returns a status URL, then `fetchResults()` polls until the job finishes.

## When to use this skill

- Screening comments, contact-form submissions, reviews or chat messages for spam.
- Adding a moderation queue that holds suspicious content for review.
- Debugging odd verdicts from `detectSpam()` / `fetchResults()`.

## Install / wiring checklist

- `composer require sharpapi/laravel-content-detect-spam`. It pulls in `sharpapi/php-core`; this skill assumes php-core ≥ 1.4.1.
- `.env`: `SHARP_API_KEY=...` is required. If it is missing, constructing the service throws `InvalidArgumentException`.
- Optional env keys, shared by every SharpAPI wrapper:
  - `SHARP_API_BASE_URL` (default `https://sharpapi.com/api/v1`)
  - `SHARP_API_JOB_STATUS_POLLING_WAIT` (default `180`): the maximum seconds `fetchResults()` keeps polling.
  - `SHARP_API_JOB_STATUS_POLLING_INTERVAL` (default `10`): seconds between polls when the API sends no `Retry-After`.
  - `SHARP_API_JOB_STATUS_USE_POLLING_INTERVAL` (default `false`): when `true`, the fixed interval above replaces the server's `Retry-After`.
- The config file is optional. To publish it: `php artisan vendor:publish --tag=sharpapi-content-detect-spam` (creates `config/sharpapi-content-detect-spam.php`).
- The service provider is auto-discovered. There is **no facade and no container binding**. Type-hint `ContentDetectSpamService` (the container builds it) or call `new ContentDetectSpamService()`. The constructor takes no arguments and reads the config.

## API & config reference

```php
use SharpAPI\ContentDetectSpam\ContentDetectSpamService;

public function detectSpam(string $text): string
```

- `$text` — the content to classify. The only parameter.

**Returns the status URL (a string), not the result.** Pass it to the inherited `fetchResults(string $statusUrl): SharpAPI\Core\DTO\SharpApiJob`, which blocks while it polls.

`SharpApiJob` has the public properties `id`, `type` (`"content_detect_spam"`), `status` (a string: `"success"` or `"failed"`) and `result` (`?stdClass`). It also has `getResultJson()`, `getResultArray()` (shallow), `getResultObject()` and `toArray()`.

Example `result` on success (shape from the SharpAPI response template; the values are illustrative):

```json
{
    "pass": false,
    "score": 85,
    "reason": "The message appears to be a solicitation for financial services, which is a common characteristic of spam."
}
```

`pass` is `true` when the text is NOT spam. `score` is a 0–100 confidence that it is spam; cast it with `(int)` because it comes from a model. `reason` is a human-readable explanation.

Exceptions:
- `SharpAPI\Core\Exceptions\ApiException`: polling ran past `SHARP_API_JOB_STATUS_POLLING_WAIT`, or HTTP 429 retries ran out.
- `GuzzleHttp\Exception\ClientException` (4xx, e.g. 401 bad key, 422 validation) and other `GuzzleHttp\Exception\GuzzleException`s for transport or 5xx errors.

## Recipes

### Queued job (the default pattern)

```php
namespace App\Jobs;

use App\Models\Comment;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable; // Laravel 10: Dispatchable, InteractsWithQueue, Queueable, SerializesModels
use Illuminate\Support\Facades\Log;
use SharpAPI\Core\Enums\SharpApiJobStatusEnum;
use SharpAPI\Core\Exceptions\ApiException;
use SharpAPI\ContentDetectSpam\ContentDetectSpamService;

class ScreenCommentForSpam implements ShouldQueue
{
    use Queueable;

    public int $timeout = 240; // must exceed SHARP_API_JOB_STATUS_POLLING_WAIT (180)

    public int $tries = 1;     // every retry re-submits the text and is billed again

    public function __construct(public Comment $comment) {}

    public function handle(ContentDetectSpamService $service): void
    {
        try {
            $statusUrl = $service->detectSpam($this->comment->body);
            $job = $service->fetchResults($statusUrl); // blocks and polls; no loop needed
        } catch (ApiException|GuzzleException $e) {
            Log::warning('SharpAPI detectSpam failed: '.$e->getMessage());

            return;
        }

        if ($job->status !== SharpApiJobStatusEnum::SUCCESS->value) {
            Log::warning('SharpAPI detectSpam job did not succeed', $job->toArray());

            return;
        }

        $isSpam = ! ($job->result->pass ?? true);
        $score = (int) ($job->result->score ?? 0);
        $this->comment->update(['is_spam' => $isSpam, 'spam_score' => $score]);
    }
}
```

Resolve the service in `handle()`, as above, and never store it on a job property. It holds a Guzzle client, which cannot be serialized onto the queue.

## Gotchas

php-core is a transitive dependency, so these rules are repeated here:

1. **`fetchResults()` already polls.** It sleeps between polls (honouring `Retry-After` and rate-limit headers) until the job succeeds, fails or `SHARP_API_JOB_STATUS_POLLING_WAIT` runs out. Never write your own `while ($status === 'pending')` loop, and never call `detectSpam()` again to "retry": each call is a new billed job.
2. **A failed job does not throw.** Always compare `$job->status` with `SharpApiJobStatusEnum::SUCCESS->value` (`SharpAPI\Core\Enums\SharpApiJobStatusEnum`). On failure `result` can be an empty `stdClass`, so reading `$job->result->field` without `?? null` raises an "Undefined property" `ErrorException` in Laravel.
3. **Never call `fetchResults()` inside an HTTP request.** It can block for up to 180 s. Use a queued job whose `$timeout` exceeds the polling wait, keep `$tries` low, and make the worker/Horizon supervisor `timeout` at least the job timeout, with the queue connection's `retry_after` above it. Artisan commands are fine to run inline.
4. **For arrays, decode the JSON:** `json_decode($job->getResultJson(), true)`. `getResultArray()` only converts the top level, so nested objects stay `stdClass`, and list results arrive as objects with numeric keys.
5. `pass` means "passed the spam check". `pass: false` is spam. Don't invert it. Decide your own threshold on `score` rather than trusting `pass` alone for borderline content.

## Testing

- Mock the service. It must reach your code through the container (constructor/`handle()` injection or `app(ContentDetectSpamService::class)`); `new ContentDetectSpamService()` bypasses the mock.

```php
use SharpAPI\Core\DTO\SharpApiJob;
use SharpAPI\ContentDetectSpam\ContentDetectSpamService;

$this->mock(ContentDetectSpamService::class, function ($mock) {
    $mock->shouldReceive('detectSpam')->once()->andReturn('https://sharpapi.com/api/v1/job/status/fake-id');
    $mock->shouldReceive('fetchResults')->once()->andReturn(new SharpApiJob(
        id: 'fake-id',
        type: 'content_detect_spam',
        status: 'success',
        result: (object) ['pass' => false, 'score' => 92, 'reason' => 'Promotional link farm'],
    ));
});
```

- Test the failure path too: return `status: 'failed'` with `result: new \stdClass`.
- `Http::fake()` does **not** intercept these calls, because php-core sends them through its own Guzzle client. Mock the service instead. Without a mock, a test with no `SHARP_API_KEY` throws `InvalidArgumentException` as soon as the service is built.
