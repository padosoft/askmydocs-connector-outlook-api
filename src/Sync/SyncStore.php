<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorMicrosoftMail\Sync;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;

/** Small per-folder pages and individual message receipts; no mailbox-sized JSON blob. */
final class SyncStore
{
    public readonly string $scope;

    public function __construct(private readonly ConnectorInstallation $installation, private readonly string $mailbox)
    {
        $this->scope = hash('sha256', json_encode([$installation->tenant_id, (int) $installation->id, $mailbox], JSON_THROW_ON_ERROR));
    }

    public function key(string $id): string
    {
        return hash('sha256', $this->scope.'\0'.$id);
    }

    public function get(string $kind, string $id): array
    {
        $row = $this->query($kind)->where('key', $this->key($id))->first();

        return $row === null ? [] : json_decode($row->data, true, 512, JSON_THROW_ON_ERROR);
    }

    public function put(string $kind, string $id, array $data, array $columns = []): void
    {
        $now = now();
        if ($kind === 'messages') {
            $columns = array_merge(['pending_tombstone' => (bool) ($data['pending_tombstone'] ?? false)], $columns);
        }
        $this->query($kind)->updateOrInsert(['key' => $this->key($id)], array_merge([
            'scope' => $this->scope,
            'tenant_id' => $this->installation->tenant_id,
            'installation_id' => $this->installation->id,
            'mailbox_id' => $this->mailbox,
            'data' => json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'created_at' => $now,
            'updated_at' => $now,
        ], $columns));
    }

    public function query(string $kind): Builder
    {
        if (! in_array($kind, ['runs', 'folders', 'messages'], true)) {
            throw new \InvalidArgumentException('Invalid state collection');
        }

        return DB::table('microsoft_mail_sync_'.$kind)->where('scope', $this->scope)->where('tenant_id', $this->installation->tenant_id)->where('installation_id', $this->installation->id);
    }

    public static function forgetInstallation(ConnectorInstallation $installation): void
    {
        foreach (['runs', 'folders', 'messages'] as $kind) {
            DB::table('microsoft_mail_sync_'.$kind)->where('tenant_id', $installation->tenant_id)->where('installation_id', $installation->id)->delete();
        }
    }
}
