<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorMicrosoftMail\Tests\Feature;

use Carbon\Carbon;
use Illuminate\Support\Facades\Event;
use Padosoft\AskMyDocsConnectorBase\ProvenanceTier;
use Padosoft\AskMyDocsConnectorMicrosoftMail\MicrosoftMailConnector;
use Padosoft\AskMyDocsConnectorMicrosoftMail\Tests\Support\GraphFixtures;
use Padosoft\AskMyDocsConnectorMicrosoftMail\Tests\Support\PreparedListener;
use Padosoft\AskMyDocsConnectorMicrosoftMail\Tests\TestCase;

final class SettingsTest extends TestCase
{
    public function test_operator_allowlist_replaces_default_list_and_never_expands_silently(): void
    {
        $installation = $this->installation(['folders' => ['include' => ['inbox-id']], 'attachments' => ['allowed_extensions' => ['txt']]]);
        $this->vault->setCredentials($installation->id, 'synthetic', 'synthetic-refresh', now()->addHour(), ['mailbox_id' => 'directory:account', 'mailbox_path' => '/me', 'microsoft_directory_id' => 'directory', 'microsoft_account_id' => 'account', 'scope' => 'Mail.Read User.Read']);
        $graph = new GraphFixtures;
        $graph->messages['A'] = GraphFixtures::message('A');
        $graph->attachments['A'] = [
            ['@odata.type' => '#microsoft.graph.fileAttachment', 'id' => 'pdf', 'name' => 'file.pdf', 'contentType' => 'application/pdf', 'size' => 10],
            ['@odata.type' => '#microsoft.graph.fileAttachment', 'id' => 'text', 'name' => 'file.txt', 'contentType' => 'text/plain', 'size' => 3, 'contentBytes' => base64_encode('one')],
        ];
        $graph->pages['inbox-id']['baseline'] = ['value' => [['id' => 'A']], '@odata.deltaLink' => GraphFixtures::delta('inbox-id', 'done')];
        $graph->install();
        $listener = new PreparedListener;
        Event::listen('askmydocs.email.prepared.v1', $listener(...));
        $this->ingestion->receipt = $listener->receive(...);
        app(MicrosoftMailConnector::class)->syncFull($installation->id);
        $record = array_values($listener->registered)[0];
        $this->assertSame('excluded', $record['manifest'][0]['status']);
        $this->assertSame('extension_not_allowed', $record['manifest'][0]['reason']);
        $this->assertCount(2, $this->ingestion->documents);
    }

    public function test_default_provenance_and_schema_are_declarative_nonsecret_and_tenant_scoped(): void
    {
        $installation = $this->installation();
        $connector = app(MicrosoftMailConnector::class);
        $this->assertSame(ProvenanceTier::UntrustedExternal, $connector->provenanceTier($installation->id));
        $installation->config_json = ['provenance_tier' => 'trusted-internal'];
        $installation->save();
        $this->assertSame(ProvenanceTier::TrustedInternal, $connector->provenanceTier($installation->id));
        foreach ($connector->connectionSettingsSchema() as $field) {
            $this->assertSame('config', $field['target']);
            $this->assertFalse($field['secret']);
        }
        $this->assertContains('Padosoft\\AskMyDocsConnectorMicrosoftMail\\MicrosoftMailConnector', json_decode(file_get_contents(__DIR__.'/../../composer.json'), true)['extra']['askmydocs']['connectors']);
    }

    public function test_widening_window_and_folder_rename_rebaseline_without_changing_content_identity(): void
    {
        Carbon::setTestNow('2026-10-14T12:00:00Z');
        try {
            $installation = $this->installation(['folders' => ['include' => ['inbox-id']], 'sync' => ['date_window_days' => 1]]);
            $this->vault->setCredentials($installation->id, 'synthetic', 'synthetic-refresh', now()->addHour(), ['mailbox_id' => 'directory:account', 'mailbox_path' => '/me', 'microsoft_directory_id' => 'directory', 'microsoft_account_id' => 'account', 'scope' => 'Mail.Read User.Read']);
            $graph = new GraphFixtures;
            $graph->messages['A'] = GraphFixtures::message('A');
            $graph->pages['inbox-id']['baseline'] = ['value' => [['id' => 'A']], '@odata.deltaLink' => GraphFixtures::delta('inbox-id', 'done')];
            $graph->install();
            $connector = app(MicrosoftMailConnector::class);
            $connector->syncFull($installation->id);
            $this->assertSame([], $this->ingestion->documents);
            $installation->config_json = ['folders' => ['include' => ['inbox-id']], 'sync' => ['date_window_days' => 0]];
            $installation->save();
            $connector->syncIncremental($installation->id, null);
            $this->assertCount(1, $this->ingestion->documents);
            $old = array_values($this->ingestion->documents)[0];
            $graph->folders[0]['displayName'] = 'Renamed folder';
            $connector->syncIncremental($installation->id, null);
            $new = array_values($this->ingestion->documents)[0];
            $this->assertSame(['Renamed folder'], $new['metadata']['email_folder_names']);
            $this->assertSame($old['metadata']['email_message_key'], $new['metadata']['email_message_key']);
            $this->assertSame($old['metadata']['email_revision_hash'], $new['metadata']['email_revision_hash']);
            $this->assertSame($old['relativePath'], $new['relativePath']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_shared_sync_never_uses_the_personal_mailbox_and_scopes_delta(): void
    {
        $target = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
        $mailbox = 'directory:'.$target;
        $installation = $this->installation(['mailbox' => ['target_id' => $target], 'folders' => ['include' => ['123']]]);
        $this->vault->setCredentials($installation->id, 'synthetic', 'synthetic-refresh', now()->addHour(), ['mailbox_id' => $mailbox, 'mailbox_path' => '/users/'.$target, 'mailbox_target_id' => $target, 'microsoft_directory_id' => 'directory', 'microsoft_account_id' => 'account', 'scope' => 'Mail.Read User.Read Mail.Read.Shared']);
        $graph = new GraphFixtures;
        $graph->folders = [['id' => '123', 'displayName' => 'Shared inbox', 'childFolderCount' => 0]];
        $graph->messages['A'] = GraphFixtures::message('A', '123');
        $graph->pages['123']['baseline'] = ['value' => [['id' => 'A']], '@odata.deltaLink' => str_replace('/me/', '/users/'.$target.'/', GraphFixtures::delta('123', 'done'))];
        $graph->install();
        app(MicrosoftMailConnector::class)->syncFull($installation->id);
        $this->assertSame($mailbox, array_values($this->ingestion->documents)[0]['metadata']['email_mailbox_id']);
        $this->assertSame(['123'], array_values($this->ingestion->documents)[0]['metadata']['email_folder_ids']);
        foreach ($graph->requests as $request) {
            $this->assertStringContainsString('/users/'.$target.'/', $request->url());
            $this->assertStringNotContainsString('/me/', $request->url());
        }
    }
}
