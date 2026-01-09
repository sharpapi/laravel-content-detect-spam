<?php

declare(strict_types=1);

namespace SharpAPI\ContentDetectSpam;

use GuzzleHttp\Exception\GuzzleException;
use InvalidArgumentException;
use SharpAPI\Core\Client\SharpApiClient;

/**
 * @api
 */
class ContentDetectSpamService extends SharpApiClient
{
    /**
     * Initializes a new instance of the class.
     *
     * @throws InvalidArgumentException if the API key is empty.
     */
    public function __construct()
    {
        parent::__construct(config('sharpapi-content-detect-spam.api_key'));
        $this->setApiBaseUrl(
            config(
                'sharpapi-content-detect-spam.base_url',
                'https://sharpapi.com/api/v1'
            )
        );
        $this->setApiJobStatusPollingInterval(
            (int) config(
                'sharpapi-content-detect-spam.api_job_status_polling_interval',
                5)
        );
        $this->setApiJobStatusPollingWait(
            (int) config(
                'sharpapi-content-detect-spam.api_job_status_polling_wait',
                180)
        );
        $this->setUserAgent('SharpAPILaravelContentDetectSpam/1.0.0');
    }

    /**
     * Checks if provided content passes a spam filtration test.
     * Provides a percentage confidence score and an explanation
     * for whether it is considered spam or not.
     * This information is useful for moderators to make a final decision.
     *
     * @throws GuzzleException
     *
     * @api
     */
    public function detectSpam(string $text): string
    {
        $response = $this->makeRequest(
            'POST',
            '/content/detect_spam',
            ['content' => $text]
        );

        return $this->parseStatusUrl($response);
    }
}