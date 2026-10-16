<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorMicrosoftMail\Tests\Feature;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Padosoft\AskMyDocsConnectorBase\Contracts\ConnectorIngestionContract;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;
use Padosoft\AskMyDocsConnectorMicrosoftMail\Graph\GraphException;
use Padosoft\AskMyDocsConnectorMicrosoftMail\MicrosoftMailConnector;
use Padosoft\AskMyDocsConnectorMicrosoftMail\Sync\SyncStore;
use Padosoft\AskMyDocsConnectorMicrosoftMail\Tests\Support\FakeIngestion;
use Padosoft\AskMyDocsConnectorMicrosoftMail\Tests\Support\GraphFixtures;
use Padosoft\AskMyDocsConnectorMicrosoftMail\Tests\TestCase;

final class SyncEdgeCasesTest extends TestCase
{
    private GraphFixtures $graph;

    protected function setUp(): void
    {
        parent::setUp();
        $this->graph = new GraphFixtures;
        $this->graph->install();
    }

    private function connected(array $config = []): ConnectorInstallation
    {
        $installation = $this->installation(array_replace_recursive(['folders' => ['include' => ['inbox-id']]], $config));
        $this->vault->setCredentials($installation->id, 'synthetic-access', 'synthetic-refresh', now()->addHour(), ['mailbox_id' => 'directory:account', 'mailbox_path' => '/me', 'mailbox_target_id' => null, 'microsoft_directory_id' => 'directory', 'microsoft_account_id' => 'account', 'scope' => 'offline_access Mail.Read User.Read openid']);

        return $installation;
    }

    private function connector(): MicrosoftMailConnector
    {
        return app(MicrosoftMailConnector::class);
    }

    private function baseline(ConnectorInstallation $installation): void
    {
        $this->graph->messages['A'] = GraphFixtures::message('A');
        $this->graph->pages['inbox-id']['baseline'] = ['value' => [['id' => 'A']], '@odata.deltaLink' => GraphFixtures::delta('inbox-id', 'done')];
        $this->connector()->syncFull($installation->id);
    }

    private function changedPage(): void
    {
        $this->graph->pages['inbox-id']['done'] = ['value' => [['id' => 'A']], '@odata.deltaLink' => GraphFixtures::delta('inbox-id', 'changed')];
    }

    private function attachment(string $id): array
    {
        return ['@odata.type' => '#microsoft.graph.fileAttachment', 'id' => $id, 'name' => 'same.txt', 'contentType' => 'text/plain', 'size' => 5, 'contentBytes' => base64_encode('bytes')];
    }

    private function sourcePath(array $document): string
    {
        return $this->ingestion->resolveKbSourcePath($document['relativePath'])['absolute'];
    }

    public function test_removed_attachment_and_shifted_slots_retire_only_obsolete_scoped_sources(): void
    {
        $installation = $this->connected(['reconciliation' => ['enabled' => true]]);
        $this->graph->attachments['A'] = [$this->attachment('a'), $this->attachment('b')];
        $this->baseline($installation);
        $old = array_values(array_filter($this->ingestion->documents, static fn (array $doc): bool => isset($doc['metadata']['email_attachment_id'])));
        $body = array_values(array_filter($this->ingestion->documents, static fn (array $doc): bool => ! isset($doc['metadata']['email_attachment_id'])))[0];
        $this->graph->attachments['A'] = [$this->attachment('b')];
        $this->changedPage();
        $this->connector()->syncIncremental($installation->id, null);
        $this->assertCount(2, $this->ingestion->deletions);
        $this->assertSame(array_column(array_column($old, 'metadata'), 'email_attachment_source_key'), array_column($this->ingestion->deletions, 'id'));
        foreach ($this->ingestion->deletions as $deletion) {
            $this->assertSame('email_attachment_source_key', $deletion['key']);
            $this->assertSame($installation->id, $deletion['installation']);
        }
        foreach ($old as $document) {
            $this->assertFalse(Storage::disk('local')->exists($this->sourcePath($document)));
        }
        $this->assertTrue(Storage::disk('local')->exists($this->sourcePath($body)));
        $record = (new SyncStore($installation, 'directory:account'))->get('messages', 'A');
        $this->assertTrue($record['complete']);
        $this->assertCount(1, $record['attachment_sources']);
        $this->assertSame([], $record['retired_attachment_sources']);
        foreach ($record['attachment_sources'] as $source) {
            $this->assertTrue(Storage::disk($source['disk'])->exists($source['absolute']));
        }
    }

    public function test_attachment_becoming_excluded_is_retired_and_remains_visible_as_coverage(): void
    {
        $installation = $this->connected(['reconciliation' => ['enabled' => true]]);
        $this->graph->attachments['A'] = [$this->attachment('a')];
        $this->baseline($installation);
        $manifest = [];
        Event::listen('askmydocs.email.prepared.v1', static function (array $envelope) use (&$manifest): void {
            $manifest = $envelope['manifest'];
        });
        $this->graph->attachments['A'][0]['isInline'] = true;
        $this->changedPage();
        $this->connector()->syncIncremental($installation->id, null);
        $this->assertSame('excluded', $manifest[0]['status']);
        $this->assertNull($manifest[0]['hash']);
        $this->assertCount(1, $this->ingestion->deletions);
        $this->assertCount(1, Storage::disk('local')->allFiles());
    }

    public function test_cleanup_failure_preserves_backlog_and_cursor_and_resume_does_not_redispatch_new_sources(): void
    {
        $installation = $this->connected(['reconciliation' => ['enabled' => true]]);
        $this->graph->attachments['A'] = [$this->attachment('a'), $this->attachment('b')];
        $this->baseline($installation);
        $bridge = new FailingAttachmentDeletionBridge($this->ingestion);
        $this->app->instance(ConnectorIngestionContract::class, $bridge);
        $this->graph->attachments['A'] = [$this->attachment('b')];
        $this->changedPage();
        try {
            $this->connector()->syncIncremental($installation->id, null);
            $this->fail('Failed retirement must interrupt cursor commit');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Synthetic attachment retirement failure', $exception->getMessage());
        }
        $store = new SyncStore($installation, 'directory:account');
        $record = $store->get('messages', 'A');
        $this->assertFalse($record['complete']);
        $this->assertCount(2, $record['retired_attachment_sources']);
        $this->assertSame(GraphFixtures::delta('inbox-id', 'done'), $store->get('folders', 'inbox-id')['delta']);
        $this->assertSame(0, $store->get('folders', 'inbox-id')['page']['index']);
        $dispatches = count(array_filter($this->ingestion->events, static fn (string $event): bool => $event === 'dispatch'));
        $bridge->failDeletes = false;
        $this->connector()->syncIncremental($installation->id, null);
        $this->assertSame($dispatches, count(array_filter($this->ingestion->events, static fn (string $event): bool => $event === 'dispatch')));
        $this->assertTrue($store->get('messages', 'A')['complete']);
        $this->assertSame([], $store->get('messages', 'A')['retired_attachment_sources']);
        $this->assertSame(GraphFixtures::delta('inbox-id', 'changed'), $store->get('folders', 'inbox-id')['delta']);
    }

    public function test_storage_delete_false_keeps_retirement_pending_and_prevents_cursor_commit(): void
    {
        $installation = $this->connected(['reconciliation' => ['enabled' => true]]);
        $this->graph->attachments['A'] = [$this->attachment('a')];
        $this->baseline($installation);
        $this->graph->attachments['A'] = [];
        $this->changedPage();
        $realDisk = Storage::disk('local');
        $failingDisk = \Mockery::mock($realDisk);
        $failingDisk->shouldReceive('delete')->once()->andReturn(false);
        Storage::set('local', $failingDisk);
        try {
            $this->connector()->syncIncremental($installation->id, null);
            $this->fail('A false filesystem deletion must propagate');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Obsolete email attachment source deletion failed', $exception->getMessage());
        } finally {
            Storage::set('local', $realDisk);
        }
        $store = new SyncStore($installation, 'directory:account');
        $this->assertFalse($store->get('messages', 'A')['complete']);
        $this->assertCount(1, $store->get('messages', 'A')['retired_attachment_sources']);
        $this->assertSame(GraphFixtures::delta('inbox-id', 'done'), $store->get('folders', 'inbox-id')['delta']);
        $this->connector()->syncIncremental($installation->id, null);
        $this->assertTrue($store->get('messages', 'A')['complete']);
        $this->assertSame([], $store->get('messages', 'A')['retired_attachment_sources']);
    }

    public function test_oversized_delta_page_is_rejected_before_caching_or_fetching_messages(): void
    {
        $installation = $this->connected();
        $items = array_map(static fn (int $index): array => ['id' => 'message-'.$index], range(0, 1000));
        $this->graph->pages['inbox-id']['baseline'] = ['value' => $items, '@odata.deltaLink' => GraphFixtures::delta('inbox-id', 'too-many')];
        try {
            $this->connector()->syncFull($installation->id);
            $this->fail('Delta pages must be bounded independent of the upstream top hint');
        } catch (\UnexpectedValueException) {
        }
        $state = (new SyncStore($installation, 'directory:account'))->get('folders', 'inbox-id');
        $this->assertArrayNotHasKey('page', $state);
        $this->assertNull($state['delta']);
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertSame([], $this->ingestion->documents);
    }

    public function test_attachment_retirement_is_opt_in(): void
    {
        $installation = $this->connected();
        $this->graph->attachments['A'] = [$this->attachment('a')];
        $this->baseline($installation);
        $this->graph->attachments['A'] = [];
        $this->changedPage();
        $this->connector()->syncIncremental($installation->id, null);
        $this->assertSame([], $this->ingestion->deletions);
        $this->assertCount(2, Storage::disk('local')->allFiles());
    }

    public function test_full_request_promotes_an_incomplete_incremental_round_to_a_complete_baseline(): void
    {
        $installation = $this->connected(['sync' => ['max_messages' => 1]]);
        $this->baseline($installation);
        foreach (['B', 'C', 'history-only'] as $id) {
            $this->graph->messages[$id] = GraphFixtures::message($id);
        }
        $this->graph->pages['inbox-id']['done'] = ['value' => [['id' => 'B'], ['id' => 'C']], '@odata.deltaLink' => GraphFixtures::delta('inbox-id', 'incremental-done')];
        $this->connector()->syncIncremental($installation->id, null);
        $this->assertFalse($this->connector()->syncStatus($installation->id)['complete']);
        $this->graph->pages['inbox-id']['baseline'] = ['value' => [['id' => 'A'], ['id' => 'B'], ['id' => 'C'], ['id' => 'history-only']], '@odata.deltaLink' => GraphFixtures::delta('inbox-id', 'full-done')];
        for ($batch = 0; $batch < 6; $batch++) {
            $this->connector()->syncFull($installation->id);
            if ($this->connector()->syncStatus($installation->id)['complete']) {
                break;
            }
        }
        $ids = array_column(array_column(array_values($this->ingestion->documents), 'metadata'), 'email_message_id');
        $this->assertContains('history-only', $ids);
        $this->assertTrue($this->connector()->syncStatus($installation->id)['complete']);
        $this->assertSame('baseline', $this->connector()->syncStatus($installation->id)['mode']);
    }

    public function test_project_retarget_invalidates_delta_scope_and_backfills_into_explicit_new_project(): void
    {
        $installation = $this->connected();
        $this->baseline($installation);
        $installation->project_key = 'new-project';
        $installation->save();
        $this->graph->pages['inbox-id']['done'] = ['value' => [], '@odata.deltaLink' => GraphFixtures::delta('inbox-id', 'no-changes')];
        $this->connector()->syncIncremental($installation->id, null);
        $document = array_values($this->ingestion->documents)[0];
        $this->assertSame('new-project', $document['projectKey']);
        $this->assertSame('new-project', $document['metadata']['project_key']);
    }

    public function test_requested_message_identity_mismatch_cannot_advance_a_page_or_prepare_ingest(): void
    {
        $installation = $this->connected();
        $this->graph->messages['A'] = GraphFixtures::message('different-native-id');
        $this->graph->pages['inbox-id']['baseline'] = ['value' => [['id' => 'A']], '@odata.deltaLink' => GraphFixtures::delta('inbox-id', 'done')];
        try {
            $this->connector()->syncFull($installation->id);
            $this->fail('Mismatching identity must fail');
        } catch (GraphException) {
        }
        $this->assertSame([], $this->ingestion->documents);
        $state = (new SyncStore($installation, 'directory:account'))->get('folders', 'inbox-id');
        $this->assertNull($state['delta']);
        $this->assertSame(0, $state['page']['index']);
    }

    public function test_metadata_only_read_flag_updates_keep_the_same_content_revision(): void
    {
        $installation = $this->connected();
        $this->baseline($installation);
        $original = array_values($this->ingestion->documents)[0]['metadata'];
        $envelope = [];
        Event::listen('askmydocs.email.prepared.v1', static function (array $prepared) use (&$envelope): void {
            $envelope = $prepared;
        });
        $this->graph->messages['A']['isRead'] = true;
        $this->graph->messages['A']['flag']['flagStatus'] = 'flagged';
        $this->changedPage();
        $this->connector()->syncIncremental($installation->id, null);
        $this->assertSame($original['email_body_hash'], $envelope['metadata']['email_body_hash']);
        $this->assertSame($original['email_revision_hash'], $envelope['metadata']['email_revision_hash']);
        $this->assertTrue($envelope['metadata']['converter_hints']['microsoft_mail']['is_read']);
        $this->assertCount(1, $this->ingestion->documents);
    }
}

final class FailingAttachmentDeletionBridge implements ConnectorIngestionContract
{
    public bool $failDeletes = true;

    public function __construct(private readonly FakeIngestion $delegate) {}

    public function dispatchIngestion(string $projectKey, string $relativePath, string $disk, string $title, array $metadata, string $mimeType, string $tenantId): void
    {
        $this->delegate->dispatchIngestion($projectKey, $relativePath, $disk, $title, $metadata, $mimeType, $tenantId);
    }

    public function resolveKbSourcePath(string $relativePath): array
    {
        return $this->delegate->resolveKbSourcePath($relativePath);
    }

    public function redactContent(string $content): string
    {
        return $this->delegate->redactContent($content);
    }

    public function emitAudit(string $connectorKey, string $eventType, ?int $installationId = null, ?array $metadata = null): void
    {
        $this->delegate->emitAudit($connectorKey, $eventType, $installationId, $metadata);
    }

    public function softDeleteByRemoteId(ConnectorInstallation $installation, string $metadataKey, string $remoteId): bool
    {
        if ($this->failDeletes) {
            throw new \RuntimeException('Synthetic attachment retirement failure');
        }

        return $this->delegate->softDeleteByRemoteId($installation, $metadataKey, $remoteId);
    }
}
