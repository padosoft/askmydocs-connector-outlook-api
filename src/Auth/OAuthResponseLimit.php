<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorMicrosoftMail\Auth;

use Padosoft\AskMyDocsConnectorBase\Exceptions\ConnectorApiException;
use Psr\Http\Message\ResponseInterface;

final class OAuthResponseLimit
{
    /** @return array<string,mixed> */
    public static function options(): array
    {
        return [
            'allow_redirects' => false,
            'on_headers' => static function (ResponseInterface $response): void {
                $length = $response->getHeaderLine('Content-Length');
                if (ctype_digit($length) && (int) $length > 1024 * 1024) {
                    throw new ConnectorApiException('Microsoft OAuth response exceeds the byte limit.');
                }
            },
            'progress' => static function ($downloadTotal, $downloaded): void {
                if ($downloadTotal > 1024 * 1024 || $downloaded > 1024 * 1024) {
                    throw new ConnectorApiException('Microsoft OAuth response exceeds the byte limit.');
                }
            },
        ];
    }
}
