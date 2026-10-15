<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorMicrosoftMail\Tests\Feature;

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;
use Padosoft\AskMyDocsConnectorMicrosoftMail\Graph\GraphException;
use Padosoft\AskMyDocsConnectorMicrosoftMail\MicrosoftMailConnector;
use Padosoft\AskMyDocsConnectorMicrosoftMail\Sync\SyncStore;
use Padosoft\AskMyDocsConnectorMicrosoftMail\Tests\Support\GraphFixtures;
use Padosoft\AskMyDocsConnectorMicrosoftMail\Tests\TestCase;

final class MoveReconciliationTest extends TestCase
{
    private array $pages = [];

    private array $responses = [];

    private array $requests = [];

    protected function setUp(): void
    {
        parent::setUp();
        // Isolated synthetic transport with an explicit sequence of message lookups.
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            $this->requests[] = $request;
            $this->assertSame('GET', $request->method());
            $this->assertStringContainsString('IdType="ImmutableId"', $request->header('Prefer')[0] ?? '');
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            if (str_ends_with($path, '/mailFolders/deleteditems')) {
                return Http::response(['id' => 'deleted-id']);
            }
            if (str_ends_with($path, '/mailFolders/junkemail')) {
                return Http::response(['id' => 'junk-id']);
            }
            if (str_ends_with($path, '/mailFolders')) {
                return Http::response(['value' => [['id' => 'archive-id', 'displayName' => 'Archive', 'childFolderCount' => 0], ['id' => 'inbox-id', 'displayName' => 'Inbox', 'childFolderCount' => 0]]]);
            }
            if (preg_match('~/mailFolders/([^/]+)/messages/delta$~', $path, $match)) {
                $folder = rawurldecode($match[1]);
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
                $token = $query['$skiptoken'] ?? $query['$deltatoken'] ?? 'baseline';

                return Http::response($this->pages[$folder][$token] ?? ['value' => [], '@odata.deltaLink' => GraphFixtures::delta($folder, 'done')]);
            }
            if (str_ends_with($path, '/attachments')) {
                return Http::response(['value' => []]);
            }
            if (preg_match('~/messages/([^/]+)$~', $path, $match)) {
                $id = rawurldecode($match[1]);
                $queue = &$this->responses[$id];
                if (! is_array($queue) || $queue === []) {
                    $response = 404;
                } else {
                    $response = count($queue) > 1 ? array_shift($queue) : $queue[0];
                }

                return is_int($response) ? Http::response(['error' => ['code' => $response === 404 ? 'ErrorItemNotFound' : 'ErrorSynthetic']], $response) : Http::response($response);
            }
            throw new \RuntimeException('Unexpected move reconciliation fixture route');
        });
    }

    private function connected(array $config = []): ConnectorInstallation
    {
        $installation = $this->installation(array_replace_recursive(['folders' => ['include' => ['archive-id', 'inbox-id']], 'reconciliation' => ['enabled' => true]], $config));
        $this->vault->setCredentials($installation->id, 'synthetic-access', 'synthetic-refresh', now()->addHour(), ['mailbox_id' => 'directory:account', 'mailbox_path' => '/me', 'mailbox_target_id' => null, 'microsoft_directory_id' => 'directory', 'microsoft_account_id' => 'account', 'scope' => 'offline_access Mail.Read User.Read openid']);

        return $installation;
    }

    private function connector(): MicrosoftMailConnector
    {
        return app(MicrosoftMailConnector::class);
    }

    private function baseline(ConnectorInstallation $installation): void
    {
        $this->responses['A'] = [GraphFixtures::message('A', 'archive-id')];
        $this->pages['archive-id']['baseline'] = ['value' => [['id' => 'A']], '@odata.deltaLink' => GraphFixtures::delta('archive-id', 'done')];
        $this->pages['inbox-id']['baseline'] = ['value' => [], '@odata.deltaLink' => GraphFixtures::delta('inbox-id', 'done')];
        for ($batch = 0; $batch < 5; $batch++) {
            $this->connector()->syncFull($installation->id);
            if ($this->connector()->syncStatus($installation->id)['complete']) {
                break;
            }
        }
        $this->assertTrue($this->connector()->syncStatus($installation->id)['complete']);
        $this->requests = [];
    }

    private function sourceRemoval(bool $destinationHasMessage): void
    {
        $this->pages['archive-id']['done'] = ['value' => [['id' => 'A', '@removed' => ['reason' => 'deleted']]], '@odata.deltaLink' => GraphFixtures::delta('archive-id', 'removed')];
        $this->pages['inbox-id']['done'] = ['value' => $destinationHasMessage ? [['id' => 'A']] : [], '@odata.deltaLink' => GraphFixtures::delta('inbox-id', 'finished')];
    }

    public function test_host_triage_profile_revision_never_restarts_acquisition_or_emits_prepared_email(): void
    {
        $installation = $this->connected();
        $this->baseline($installation);
        $installation->config_json = array_merge($installation->config_json, ['email_triage_profile_id' => 77, 'email_triage_enabled' => true, 'host_runtime_option' => 'changed']);
        $installation->save();
        $this->pages['archive-id']['done'] = ['value' => [], '@odata.deltaLink' => GraphFixtures::delta('archive-id', 'profile-unchanged')];
        $this->pages['inbox-id']['done'] = ['value' => [], '@odata.deltaLink' => GraphFixtures::delta('inbox-id', 'profile-unchanged')];
        $prepared = 0;
        Event::listen('askmydocs.email.prepared.v1', static function () use (&$prepared): void {
            $prepared++;
        });
        $this->connector()->syncIncremental($installation->id, null);
        $deltaRequests = 0;
        foreach ($this->requests as $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            $this->assertStringNotContainsString('/messages/A', $path);
            if (str_ends_with($path, '/messages/delta')) {
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
                $this->assertSame('done', $query['$deltatoken'] ?? null);
                $deltaRequests++;
            }
        }
        $this->assertSame(2, $deltaRequests);
        $this->assertSame(0, $prepared);
        $this->assertCount(1, $this->ingestion->documents);
        $this->assertSame('incremental', $this->connector()->syncStatus($installation->id)['mode']);
    }

    public function test_transient_source_lookup_404_before_destination_import_never_deletes_parent(): void
    {
        $installation = $this->connected();
        $this->baseline($installation);
        $original = array_values($this->ingestion->documents)[0];
        $this->sourceRemoval(true);
        $this->responses['A'] = [404, GraphFixtures::message('A', 'inbox-id')];
        $result = $this->connector()->syncIncremental($installation->id, null);
        $this->assertSame(0, $result->documentsRemoved);
        $this->assertSame([], $this->ingestion->deletions);
        $this->assertCount(1, $this->ingestion->documents);
        $current = array_values($this->ingestion->documents)[0];
        $this->assertSame($original['relativePath'], $current['relativePath']);
        $this->assertSame($original['metadata']['email_message_key'], $current['metadata']['email_message_key']);
        $this->assertSame($original['metadata']['email_revision_hash'], $current['metadata']['email_revision_hash']);
        $this->assertSame(['inbox-id'], $current['metadata']['email_folder_ids']);
        $record = (new SyncStore($installation, 'directory:account'))->get('messages', 'A');
        $this->assertFalse($record['pending_tombstone']);
        $this->assertTrue($record['active']);
    }

    public function test_incomplete_batch_keeps_pending_tombstone_until_destination_resume_is_durable(): void
    {
        $installation = $this->connected(['sync' => ['max_messages' => 1]]);
        $this->baseline($installation);
        $this->sourceRemoval(true);
        $this->responses['A'] = [404, GraphFixtures::message('A', 'inbox-id')];
        $this->connector()->syncIncremental($installation->id, null);
        $store = new SyncStore($installation, 'directory:account');
        $this->assertFalse($this->connector()->syncStatus($installation->id)['complete']);
        $this->assertTrue($store->get('messages', 'A')['pending_tombstone']);
        $this->assertSame(1, $store->query('messages')->where('pending_tombstone', true)->count());
        $this->assertSame([], $this->ingestion->deletions);
        for ($batch = 0; $batch < 4; $batch++) {
            $this->connector()->syncIncremental($installation->id, null);
            if ($this->connector()->syncStatus($installation->id)['complete']) {
                break;
            }
        }
        $this->assertTrue($this->connector()->syncStatus($installation->id)['complete']);
        $this->assertFalse($store->get('messages', 'A')['pending_tombstone']);
        $this->assertSame(0, $store->query('messages')->where('pending_tombstone', true)->count());
        $this->assertSame([], $this->ingestion->deletions);
        $this->assertSame(['inbox-id'], array_values($this->ingestion->documents)[0]['metadata']['email_folder_ids']);
    }

    public function test_failed_destination_dispatch_keeps_tombstone_pending_until_receipts_resume(): void
    {
        $installation = $this->connected();
        $this->baseline($installation);
        $this->sourceRemoval(true);
        $this->responses['A'] = [404, GraphFixtures::message('A', 'inbox-id')];
        $this->ingestion->failDispatch = true;
        try {
            $this->connector()->syncIncremental($installation->id, null);
            $this->fail('Destination dispatch failure must interrupt the round');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Synthetic dispatch failure', $exception->getMessage());
        }
        $store = new SyncStore($installation, 'directory:account');
        $this->assertTrue($store->get('messages', 'A')['pending_tombstone']);
        $this->assertSame(1, $store->query('messages')->where('pending_tombstone', true)->count());
        $this->assertSame([], $this->ingestion->deletions);
        $this->ingestion->failDispatch = false;
        $this->connector()->syncIncremental($installation->id, null);
        $this->assertFalse($store->get('messages', 'A')['pending_tombstone']);
        $this->assertSame([], $this->ingestion->deletions);
        $this->assertTrue($this->connector()->syncStatus($installation->id)['complete']);
    }

    public function test_pending_tombstone_confirmation_has_a_durable_cursor_across_bounded_batches(): void
    {
        $installation = $this->connected(['sync' => ['max_messages' => 1]]);
        $this->responses['A'] = [GraphFixtures::message('A', 'archive-id')];
        $this->responses['B'] = [GraphFixtures::message('B', 'archive-id')];
        $this->pages['archive-id']['baseline'] = ['value' => [['id' => 'A'], ['id' => 'B']], '@odata.deltaLink' => GraphFixtures::delta('archive-id', 'done')];
        for ($batch = 0; $batch < 8; $batch++) {
            $this->connector()->syncFull($installation->id);
            if ($this->connector()->syncStatus($installation->id)['complete']) {
                break;
            }
        }
        $this->assertTrue($this->connector()->syncStatus($installation->id)['complete']);
        $this->pages['archive-id']['done'] = ['value' => [['id' => 'A', '@removed' => []], ['id' => 'B', '@removed' => []]], '@odata.deltaLink' => GraphFixtures::delta('archive-id', 'removed')];
        $this->responses['A'] = [404];
        $this->responses['B'] = [404];
        $this->connector()->syncIncremental($installation->id, null);
        $this->connector()->syncIncremental($installation->id, null);
        $store = new SyncStore($installation, 'directory:account');
        $this->assertSame(2, $store->query('messages')->where('pending_tombstone', true)->count());
        $this->assertSame([], $this->ingestion->deletions);
        $this->connector()->syncIncremental($installation->id, null);
        $this->assertCount(1, $this->ingestion->deletions);
        $this->assertSame(1, $store->query('messages')->where('pending_tombstone', true)->count());
        $this->assertNotSame('', $store->get('runs', 'run')['tombstone_after']);
        $this->connector()->syncIncremental($installation->id, null);
        $this->assertCount(2, $this->ingestion->deletions);
        $this->assertSame(0, $store->query('messages')->where('pending_tombstone', true)->count());
        $this->assertTrue($this->connector()->syncStatus($installation->id)['complete']);
    }

    public function test_persisted_delta_cannot_be_replayed_against_a_different_folder_collection(): void
    {
        $installation = $this->connected();
        $this->baseline($installation);
        $store = new SyncStore($installation, 'directory:account');
        $state = $store->get('folders', 'archive-id');
        $state['delta'] = GraphFixtures::delta('inbox-id', 'tampered');
        $store->put('folders', 'archive-id', $state);
        try {
            $this->connector()->syncIncremental($installation->id, null);
            $this->fail('A cross-folder persisted checkpoint must fail before a bearer request');
        } catch (GraphException) {
        }
        foreach ($this->requests as $request) {
            $this->assertStringNotContainsString('tampered', $request->url());
        }
        $this->assertSame([], $this->ingestion->deletions);
        $this->assertFalse($this->connector()->syncStatus($installation->id)['complete']);
    }

    public function test_completed_empty_full_rescan_confirms_absent_parent_in_the_same_round(): void
    {
        $installation = $this->connected();
        $this->baseline($installation);
        $this->pages['archive-id']['baseline'] = ['value' => [], '@odata.deltaLink' => GraphFixtures::delta('archive-id', 'empty-full')];
        $this->responses['A'] = [404];
        $result = $this->connector()->syncFull($installation->id);
        $this->assertSame(1, $result->documentsRemoved);
        $this->assertTrue($this->connector()->syncStatus($installation->id)['complete']);
        $this->assertCount(1, $this->ingestion->deletions);
        $store = new SyncStore($installation, 'directory:account');
        $this->assertFalse($store->get('messages', 'A')['active']);
        $this->assertSame(0, $store->query('messages')->where('pending_tombstone', true)->count());
    }

    public function test_true_deletion_is_confirmed_only_after_all_selected_folder_scans_finish(): void
    {
        $installation = $this->connected();
        $this->baseline($installation);
        $key = array_values($this->ingestion->documents)[0]['metadata']['email_message_key'];
        $this->sourceRemoval(false);
        $this->responses['A'] = [404];
        $result = $this->connector()->syncIncremental($installation->id, null);
        $this->assertSame(1, $result->documentsRemoved);
        $this->assertSame([['tenant' => 'tenant-a', 'installation' => $installation->id, 'key' => 'email_message_key', 'id' => $key]], $this->ingestion->deletions);
        $lookups = [];
        $destinationScan = null;
        foreach ($this->requests as $index => $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            if (str_ends_with($path, '/messages/A')) {
                $lookups[] = $index;
            }
            if (str_ends_with($path, '/mailFolders/inbox-id/messages/delta')) {
                $destinationScan = $index;
            }
        }
        $this->assertCount(2, $lookups);
        $this->assertNotNull($destinationScan);
        $this->assertGreaterThan($destinationScan, $lookups[1]);
        $record = (new SyncStore($installation, 'directory:account'))->get('messages', 'A');
        $this->assertFalse($record['active']);
        $this->assertFalse($record['pending_tombstone']);
    }

    public function test_final_lookup_failure_keeps_pending_state_and_retry_confirms_it_without_rescanning(): void
    {
        $installation = $this->connected();
        $this->baseline($installation);
        $this->sourceRemoval(false);
        $this->responses['A'] = [404, 503];
        try {
            $this->connector()->syncIncremental($installation->id, null);
            $this->fail('Confirmation service failure must propagate');
        } catch (GraphException) {
        }
        $store = new SyncStore($installation, 'directory:account');
        $this->assertTrue($store->get('messages', 'A')['pending_tombstone']);
        $this->assertFalse($this->connector()->syncStatus($installation->id)['complete']);
        $this->assertSame([], $this->ingestion->deletions);
        $this->assertTrue($store->get('folders', 'archive-id')['complete']);
        $this->assertTrue($store->get('folders', 'inbox-id')['complete']);
        $this->responses['A'] = [404];
        $this->requests = [];
        $this->connector()->syncIncremental($installation->id, null);
        $this->assertCount(1, $this->ingestion->deletions);
        $this->assertFalse($store->get('messages', 'A')['pending_tombstone']);
        foreach ($this->requests as $request) {
            $this->assertStringNotContainsString('/messages/delta', $request->url());
        }
    }

    public function test_opt_out_confirms_pending_state_without_deleting_previously_acquired_parent(): void
    {
        $installation = $this->connected(['reconciliation' => ['enabled' => false]]);
        $this->baseline($installation);
        $this->sourceRemoval(false);
        $this->responses['A'] = [404];
        $this->connector()->syncIncremental($installation->id, null);
        $record = (new SyncStore($installation, 'directory:account'))->get('messages', 'A');
        $this->assertSame([], $this->ingestion->deletions);
        $this->assertTrue($record['active']);
        $this->assertFalse($record['pending_tombstone']);
        $this->assertTrue($this->connector()->syncStatus($installation->id)['complete']);
    }

    public function test_unknown_removed_message_records_intentional_missing_without_a_pending_parent(): void
    {
        $installation = $this->connected();
        $this->pages['archive-id']['baseline'] = ['value' => [['id' => 'never-acquired', '@removed' => []]], '@odata.deltaLink' => GraphFixtures::delta('archive-id', 'done')];
        $this->responses['never-acquired'] = [404];
        $this->connector()->syncFull($installation->id);
        $store = new SyncStore($installation, 'directory:account');
        $record = $store->get('messages', 'never-acquired');
        $this->assertSame('missing', $record['excluded']);
        $this->assertFalse($record['pending_tombstone']);
        $this->assertFalse($record['active']);
        $this->assertSame([], $this->ingestion->documents);
        $this->assertSame([], $this->ingestion->deletions);
    }
}
