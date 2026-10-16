<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorMicrosoftMail\Graph;

use Padosoft\AskMyDocsConnectorBase\Exceptions\ConnectorApiException;

class GraphException extends ConnectorApiException
{
    public function __construct(string $message, public readonly int $status = 0, public readonly string $errorCode = '')
    {
        parent::__construct($message, $status);
    }
}
