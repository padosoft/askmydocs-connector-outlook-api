<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorMicrosoftMail\Tests\Support;

use Illuminate\Support\Facades\Storage;
use Padosoft\AskMyDocsConnectorBase\Contracts\ConnectorIngestionContract;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;

final class FakeIngestion implements ConnectorIngestionContract
{
    public array $documents = [];

    public array $deletions = [];

    public array $audit = [];

    public array $events = [];

    public bool $failDispatch = false;

    public bool $redact = false;

    public ?\Closure $receipt = null;

    public function dispatchIngestion(string $projectKey, string $relativePath, string $disk, string $title, array $metadata, string $mimeType, string $tenantId): void
    {
        if ($this->failDispatch) {
            throw new \RuntimeException('Synthetic dispatch failure');
        }
        if (! Storage::disk($disk)->exists($this->sourcePath($relativePath))) {
            throw new \RuntimeException('Dispatch before write');
        }
        $this->events[] = 'dispatch';
        $this->documents[$relativePath] = compact('projectKey', 'relativePath', 'disk', 'title', 'metadata', 'mimeType', 'tenantId');
        if ($this->receipt !== null) {
            ($this->receipt)($metadata, $mimeType, Storage::disk($disk)->get($this->sourcePath($relativePath)));
        }
    }

    public function resolveKbSourcePath(string $relativePath): array
    {
        $this->events[] = 'resolve';

        return ['relative' => $relativePath, 'absolute' => $this->sourcePath($relativePath), 'disk' => 'local'];
    }

    public function sourcePath(string $relativePath): string
    {
        return 'sources-prefix/'.$relativePath;
    }

    public function redactContent(string $content): string
    {
        return $this->redact ? str_replace('private@example.test', '[redacted]', $content) : $content;
    }

    public function emitAudit(string $connectorKey, string $eventType, ?int $installationId = null, ?array $metadata = null): void
    {
        $this->audit[] = compact('connectorKey', 'eventType', 'installationId', 'metadata');
    }

    public function softDeleteByRemoteId(ConnectorInstallation $installation, string $metadataKey, string $remoteId): bool
    {
        $this->deletions[] = ['tenant' => $installation->tenant_id, 'installation' => $installation->id, 'key' => $metadataKey, 'id' => $remoteId];

        return true;
    }
}
