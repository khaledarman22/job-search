<?php

namespace App\Exceptions;

use Illuminate\Http\Client\Response;

class ApifyException extends \Exception
{
    public static function fromResponse(Response $response, string $context): self
    {
        $apifyMessage = $response->json('error.message');
        $detail = is_string($apifyMessage) && $apifyMessage !== ''
            ? $apifyMessage
            : mb_substr((string) $response->body(), 0, 300);

        return new self("Apify {$context} failed (HTTP {$response->status()}): {$detail}");
    }
}
