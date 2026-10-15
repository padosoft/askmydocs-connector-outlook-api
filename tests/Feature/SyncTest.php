<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorMicrosoftMail\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Padosoft\AskMyDocsConnectorBase\Exceptions\ConnectorApiException;
use Padosoft\AskMyDocsConnectorBase\Support\TenantContext;
use Padosoft\AskMyDocsConnectorMicrosoftMail\MicrosoftMailConnector;
use Padosoft\AskMyDocsConnectorMicrosoftMail\Sync\SyncStore;
use Padosoft\AskMyDocsConnectorMicrosoftMail\Tests\Support\GraphFixtures;
use Padosoft\AskMyDocsConnectorMicrosoftMail\Tests\TestCase;

final class SyncTest extends TestCase
{
    private GraphFixtures $graph;

    protected function setUp(): void
    {
        parent::setUp();
        $this->graph = new GraphFixtures;
        $this->graph->install();
    }

    private function connected(array $config = [])
    {
        $installation = $this->installation(array_replace_recursive(['folders' => ['include' => ['inbox-id']]], $config));
        $this->vault->setCredentials($installation->id, 'synthetic-access', 'synthetic-refresh', now()->addHour(), ['mailbox_id' => 'directory:account', 'mailbox_path' => '/me', 'mailbox_target_id' => null, 'microsoft_directory_id' => 'directory', 'microsoft_account_id' => 'account', 'scope' => 'offline_access Mail.Read User.Read openid']);

        return $installation;
    }

    private function connector(): MicrosoftMailConnector
    {
        return app(MicrosoftMailConnector::class);
    }

    public function test_full_history_and_synchronous_preparation_of_body_and_attachments(): void
    {
        $installation = $this->connected();
        $this->ingestion->redact = true;
        $this->graph->messages['A'] = GraphFixtures::message('A');
        $this->graph->messages['B'] = GraphFixtures::message('B');
        $this->graph->attachments['A'] = [
            ['@odata.type' => '#microsoft.graph.fileAttachment', 'id' => 'b', 'name' => '../same.txt', 'contentType' => 'text/plain', 'size' => 3, 'contentBytes' => base64_encode('two')],
            ['@odata.type' => '#microsoft.graph.fileAttachment', 'id' => 'a', 'name' => '../same.txt', 'contentType' => 'text/plain', 'size' => 3, 'contentBytes' => base64_encode('one')],
            ['@odata.type' => '#microsoft.graph.referenceAttachment', 'id' => 'ref', 'name' => 'remote.pdf', 'sourceUrl' => 'https://untrusted.test/secret'],
        ];
        $this->graph->pages['inbox-id'] = ['baseline' => ['value' => [['id' => 'A']], '@odata.nextLink' => GraphFixtures::next('inbox-id', 'second')], 'second' => ['value' => [['id' => 'B']], '@odata.deltaLink' => GraphFixtures::delta('inbox-id', 'done')]];
        $envelopes = [];
        Event::listen('askmydocs.email.prepared.v1', function (array $envelope) use (&$envelopes): void {
            $this->assertSame([], Storage::disk('local')->allFiles('sources-prefix/connectors/microsoft-mail/'.hash('sha256', 'tenant-a').'/'.$envelope['installation_id'].'/'.hash('sha256', $envelope['metadata']['email_message_key'])));
            $envelopes[] = $envelope;
            $this->ingestion->events[] = 'prepared';
        });
        $result = $this->connector()->syncFull($installation->id);
        $this->assertSame(2, $result->documentsAdded);
        $this->assertTrue($this->connector()->syncStatus($installation->id)['complete']);
        $this->assertCount(4, $this->ingestion->documents);
        $this->assertSame('prepared', $this->ingestion->events[0]);
        $this->assertSame(['a', 'b', 'ref'], array_column($envelopes[0]['manifest'], 'id'));
        $this->assertSame('excluded', $envelopes[0]['manifest'][2]['status']);
        $parent = $envelopes[0]['metadata'];
        foreach ($this->ingestion->documents as $doc) {
            $this->assertSame('tenant-a', $doc['tenantId']);
            $bytes = Storage::disk($doc['disk'])->get($this->ingestion->sourcePath($doc['relativePath']));
            if ($doc['metadata']['email_message_id'] === 'A') {
                $this->assertSame($parent['email_revision_hash'], $doc['metadata']['email_revision_hash']);
                $this->assertSame($parent['email_message_key'], $doc['metadata']['email_message_key']);
                if ($doc['mimeType'] === 'text/markdown') {
                    $this->assertSame(hash('sha256', $bytes), $parent['email_body_hash']);
                    $this->assertStringNotContainsString('private@example.test', $bytes);
                }
            }
        }
        Http::assertSentCount(count($this->graph->requests));
    }

    public function test_crash_mid_page_never_skips_failed_message_and_replay_is_idempotent(): void
    {
        $installation = $this->connected();
        foreach (['A', 'B', 'C'] as $id) {
            $this->graph->messages[$id] = GraphFixtures::message($id);
        }
        $this->graph->pages['inbox-id']['baseline'] = ['value' => [['id' => 'A'], ['id' => 'B'], ['id' => 'C']], '@odata.deltaLink' => GraphFixtures::delta('inbox-id', 'done')];
        $this->graph->failMessage = 'B';
        try {
            $this->connector()->syncFull($installation->id);
            $this->fail('Failure must propagate');
        } catch (ConnectorApiException) {
        }
        $store = new SyncStore($installation, 'directory:account');
        $state = $store->get('folders', 'inbox-id');
        $this->assertNull($state['delta']);
        $this->assertSame(1, $state['page']['index']);
        $this->assertCount(1, $this->ingestion->documents);
        $this->graph->failMessage = null;
        $result = $this->connector()->syncFull($installation->id);
        $this->assertSame(2, $result->documentsAdded);
        $this->assertCount(3, $this->ingestion->documents);
        $this->assertSame(GraphFixtures::delta('inbox-id', 'done'), $store->get('folders', 'inbox-id')['delta']);
    }

    public function test_listener_failure_precedes_put_and_checkpoint(): void
    {
        $installation = $this->connected();
        $this->graph->messages['A'] = GraphFixtures::message('A');
        $this->graph->pages['inbox-id']['baseline'] = ['value' => [['id' => 'A']], '@odata.deltaLink' => GraphFixtures::delta('inbox-id', 'done')];
        Event::listen('askmydocs.email.prepared.v1', static function (): void {
            throw new \RuntimeException('Synthetic listener failure');
        });
        try {
            $this->connector()->syncFull($installation->id);
            $this->fail('Failure must propagate');
        } catch (\RuntimeException $e) {
            $this->assertSame('Synthetic listener failure', $e->getMessage());
        }
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertSame([], $this->ingestion->documents);
        $state = (new SyncStore($installation, 'directory:account'))->get('folders', 'inbox-id');
        $this->assertNull($state['delta']);
        $this->assertSame(0, $state['page']['index']);
    }

    public function test_dispatch_failure_replays_same_manifest_and_no_commit(): void
    {
        $installation = $this->connected();
        $this->graph->messages['A'] = GraphFixtures::message('A');
        $this->graph->pages['inbox-id']['baseline'] = ['value' => [['id' => 'A']], '@odata.deltaLink' => GraphFixtures::delta('inbox-id', 'done')];
        $envelopes = [];
        Event::listen('askmydocs.email.prepared.v1', static function (array $envelope) use (&$envelopes): void {
            $envelopes[] = $envelope;
        });
        $this->ingestion->failDispatch = true;
        try {
            $this->connector()->syncFull($installation->id);
            $this->fail('Failure must propagate');
        } catch (\RuntimeException) {
        }
        $this->assertFalse($this->connector()->syncStatus($installation->id)['complete']);
        $this->ingestion->failDispatch = false;
        $this->connector()->syncFull($installation->id);
        $this->assertCount(2, $envelopes);
        $this->assertSame($envelopes[0], $envelopes[1]);
        $this->assertCount(1, $this->ingestion->documents);
    }

    public function test_bounded_batch_resumes_cached_page_without_restart(): void
    {
        $installation = $this->connected(['sync' => ['max_messages' => 1]]);
        foreach (['A', 'B'] as $id) {
            $this->graph->messages[$id] = GraphFixtures::message($id);
        }
        $this->graph->pages['inbox-id']['baseline'] = ['value' => [['id' => 'A'], ['id' => 'B']], '@odata.deltaLink' => GraphFixtures::delta('inbox-id', 'done')];
        $this->connector()->syncFull($installation->id);
        $this->assertFalse($this->connector()->syncStatus($installation->id)['complete']);
        $this->assertCount(1, $this->ingestion->documents);
        $this->connector()->syncFull($installation->id);
        $this->assertTrue($this->connector()->syncStatus($installation->id)['complete']);
        $this->assertCount(2, $this->ingestion->documents);
    }

    public function test_move_across_selected_folders_retains_key_path_revision_without_deletion(): void
    {
        $installation = $this->connected(['folders' => ['include' => ['inbox-id', 'archive-id']], 'reconciliation' => ['enabled' => true]]);
        $this->graph->messages['A'] = GraphFixtures::message('A');
        $this->graph->pages['inbox-id']['baseline'] = ['value' => [['id' => 'A']], '@odata.deltaLink' => GraphFixtures::delta('inbox-id', 'done')];
        $this->connector()->syncFull($installation->id);
        $old = array_values($this->ingestion->documents)[0];
        $this->graph->messages['A']['parentFolderId'] = 'archive-id';
        $this->graph->pages['inbox-id']['done'] = ['value' => [['id' => 'A', '@removed' => ['reason' => 'deleted']]], '@odata.deltaLink' => GraphFixtures::delta('inbox-id', 'moved')];
        $this->graph->pages['archive-id']['committed'] = ['value' => [['id' => 'A']], '@odata.deltaLink' => GraphFixtures::delta('archive-id', 'moved')];
        $this->connector()->syncIncremental($installation->id, now());
        $new = array_values($this->ingestion->documents)[0];
        $this->assertCount(1, $this->ingestion->documents);
        $this->assertSame([], $this->ingestion->deletions);
        $this->assertSame($old['relativePath'], $new['relativePath']);
        $this->assertSame($old['metadata']['email_revision_hash'], $new['metadata']['email_revision_hash']);
        $this->assertSame(['archive-id'], $new['metadata']['email_folder_ids']);
    }

    public function test_deleted_or_out_of_scope_message_reconciles_only_composed_key(): void
    {
        $installation = $this->connected(['reconciliation' => ['enabled' => true]]);
        $this->graph->messages['A'] = GraphFixtures::message('A');
        $this->graph->pages['inbox-id']['baseline'] = ['value' => [['id' => 'A']], '@odata.deltaLink' => GraphFixtures::delta('inbox-id', 'done')];
        $this->connector()->syncFull($installation->id);
        $parent = array_values($this->ingestion->documents)[0]['metadata'];
        unset($this->graph->messages['A']);
        $this->graph->pages['inbox-id']['done'] = ['value' => [['id' => 'A', '@removed' => []]], '@odata.deltaLink' => GraphFixtures::delta('inbox-id', 'deleted')];
        $result = $this->connector()->syncIncremental($installation->id, null);
        $this->assertSame(1, $result->documentsRemoved);
        $this->assertSame([['tenant' => 'tenant-a', 'installation' => $installation->id, 'key' => 'email_message_key', 'id' => $parent['email_message_key']]], $this->ingestion->deletions);
    }

    public function test_invalid_cursor_rebuilds_only_affected_folder_and_new_config_backfills(): void
    {
        $installation = $this->connected();
        $this->connector()->syncFull($installation->id);
        $this->graph->pages['inbox-id']['committed'] = 410;
        $this->graph->messages['A'] = GraphFixtures::message('A');
        $this->graph->pages['inbox-id']['baseline'] = ['value' => [['id' => 'A']], '@odata.deltaLink' => GraphFixtures::delta('inbox-id', 'recovered')];
        $this->connector()->syncIncremental($installation->id, null);
        $this->assertCount(1, $this->ingestion->documents);
        $installation->config_json = ['folders' => ['include' => ['inbox-id', 'archive-id']], 'sync' => ['date_window_days' => 0]];
        $installation->save();
        $this->graph->messages['B'] = GraphFixtures::message('B', 'archive-id');
        $this->graph->pages['archive-id']['baseline'] = ['value' => [['id' => 'B']], '@odata.deltaLink' => GraphFixtures::delta('archive-id', 'done')];
        $this->connector()->syncIncremental($installation->id, null);
        $this->assertCount(2, $this->ingestion->documents);
    }

    public function test_no_prune_for_incomplete_baseline_or_opt_out(): void
    {
        $installation = $this->connected(['reconciliation' => ['enabled' => true]]);
        $this->graph->messages['A'] = GraphFixtures::message('A');
        $this->graph->pages['inbox-id']['baseline'] = ['value' => [['id' => 'A']], '@odata.deltaLink' => GraphFixtures::delta('inbox-id', 'done')];
        $this->connector()->syncFull($installation->id);
        unset($this->graph->messages['A']);
        $installation->config_json = ['folders' => ['include' => ['inbox-id']], 'reconciliation' => ['enabled' => true], 'sync' => ['max_pages' => 1]];
        $installation->save();
        $this->graph->pages['inbox-id']['baseline'] = ['value' => [], '@odata.nextLink' => GraphFixtures::next('inbox-id', 'unfinished')];
        $this->connector()->syncFull($installation->id);
        $this->assertSame([], $this->ingestion->deletions);
        $this->assertFalse($this->connector()->syncStatus($installation->id)['complete']);
    }

    public function test_health_discovery_and_tenant_isolation_are_read_only(): void
    {
        $installation = $this->connected();
        $extra = $this->vault->getExtra($installation->id);
        $this->assertSame('healthy', $this->connector()->health($installation->id)->state);
        $this->assertSame(['inbox-id', 'archive-id'], $this->connector()->listAvailableFolders($installation->id));
        $this->assertSame($extra, $this->vault->getExtra($installation->id));
        $this->assertSame(0, DB::table('microsoft_mail_sync_runs')->count());
        $this->assertSame([], $this->ingestion->documents);
        app(TenantContext::class)->set('tenant-b');
        $this->expectException(\InvalidArgumentException::class);
        $this->connector()->syncFull($installation->id);
    }

    public function test_mailbox_installations_do_not_share_state_or_paths_and_disconnect_scopes_cleanup(): void
    {
        $a = $this->connected();
        $b = $this->connected();
        $this->graph->messages['A'] = GraphFixtures::message('A');
        $this->graph->pages['inbox-id']['baseline'] = ['value' => [['id' => 'A']], '@odata.deltaLink' => GraphFixtures::delta('inbox-id', 'done')];
        $this->connector()->syncFull($a->id);
        $this->connector()->syncFull($b->id);
        $this->assertCount(2, $this->ingestion->documents);
        $keys = array_column(array_column(array_values($this->ingestion->documents), 'metadata'), 'email_message_key');
        $this->assertNotSame($keys[0], $keys[1]);
        $this->connector()->disconnect($a->id);
        $this->assertNull($this->vault->getAccessToken($a->id));
        $this->assertNotNull($this->vault->getAccessToken($b->id));
        $this->assertSame(0, DB::table('microsoft_mail_sync_messages')->where('installation_id', $a->id)->count());
        $this->assertSame(1, DB::table('microsoft_mail_sync_messages')->where('installation_id', $b->id)->count());
    }
}
