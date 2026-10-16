<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorMicrosoftMail\Tests\Feature;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Padosoft\AskMyDocsConnectorMicrosoftMail\MicrosoftMailConnector;
use Padosoft\AskMyDocsConnectorMicrosoftMail\Tests\Support\GraphFixtures;
use Padosoft\AskMyDocsConnectorMicrosoftMail\Tests\Support\PreparedListener;
use Padosoft\AskMyDocsConnectorMicrosoftMail\Tests\TestCase;

final class TriageContractTest extends TestCase
{
    public function test_receipt_gate_replay_and_metadata_changes_share_the_same_revision(): void
    {
        $installation = $this->installation(['folders' => ['include' => ['inbox-id']]]);
        $this->vault->setCredentials($installation->id, 'synthetic-access', 'synthetic-refresh', now()->addHour(), ['mailbox_id' => 'directory:account', 'mailbox_path' => '/me', 'mailbox_target_id' => null, 'microsoft_directory_id' => 'directory', 'microsoft_account_id' => 'account', 'scope' => 'Mail.Read User.Read']);
        $message = json_decode(file_get_contents(__DIR__.'/../Fixtures/full-message.json'), true, 512, JSON_THROW_ON_ERROR);
        $id = $message['id'];
        $graph = new GraphFixtures;
        $graph->messages[$id] = $message;
        // hasAttachments=false still must discover inline and all ordinary parts.
        $graph->attachments[$id] = [
            ['@odata.type' => '#microsoft.graph.fileAttachment', 'id' => 'a', 'name' => 'same.txt', 'contentType' => 'text/plain', 'size' => 3, 'contentBytes' => base64_encode('one')],
            ['@odata.type' => '#microsoft.graph.fileAttachment', 'id' => 'b', 'name' => 'same.txt', 'contentType' => 'text/plain', 'size' => 3, 'contentBytes' => base64_encode('two')],
            ['@odata.type' => '#microsoft.graph.fileAttachment', 'id' => 'inline', 'name' => 'logo.png', 'contentType' => 'image/png', 'isInline' => true, 'size' => 4],
        ];
        $graph->pages['inbox-id']['baseline'] = ['value' => [['id' => $id]], '@odata.deltaLink' => GraphFixtures::delta('inbox-id', 'done')];
        $graph->install();
        $listener = new PreparedListener;
        Event::listen('askmydocs.email.prepared.v1', $listener(...));
        $fail = true;
        $this->ingestion->receipt = function (array $metadata, string $mime, string $bytes) use ($listener, &$fail): void {
            if ($fail && ($metadata['email_attachment_id'] ?? '') === 'b') {
                throw new \RuntimeException('Synthetic second attachment failure');
            }
            $listener->receive($metadata, $mime, $bytes);
        };
        $connector = app(MicrosoftMailConnector::class);
        try {
            $connector->syncFull($installation->id);
            $this->fail('Dispatch failure should propagate');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Synthetic second attachment failure', $exception->getMessage());
        }
        $record = array_values($listener->registered)[0];
        $this->assertTrue($record['body']);
        $this->assertSame('pending', $record['state']);
        $this->assertCount(1, $record['receipts']);
        $this->assertCount(0, $listener->ready);
        $this->assertSame('excluded', $record['manifest'][2]['status']);
        $fail = false;
        $connector->syncFull($installation->id);
        $this->assertCount(1, $listener->ready);
        $this->assertSame('ready', array_values($listener->registered)[0]['state']);
        $graph->messages[$id]['isRead'] = true;
        $graph->pages['inbox-id']['done'] = ['value' => [['id' => $id]], '@odata.deltaLink' => GraphFixtures::delta('inbox-id', 'read')];
        $connector->syncIncremental($installation->id, null);
        $this->assertCount(1, $listener->ready);
        $this->assertCount(3, $this->ingestion->documents);
        $body = array_values(array_filter($this->ingestion->documents, static fn (array $doc): bool => $doc['mimeType'] === 'text/markdown'))[0];
        $content = Storage::disk($body['disk'])->get($this->ingestion->sourcePath($body['relativePath']));
        $this->assertStringContainsString('Contenuto completo oltre il preview.', $content);
        $this->assertStringNotContainsString('unsafe()', $content);
        $this->assertSame(hash('sha256', $content), $body['metadata']['email_body_hash']);
        $this->assertSame($record['metadata']['email_revision_hash'], $body['metadata']['email_revision_hash']);
        $this->assertTrue($body['metadata']['converter_hints']['microsoft_mail']['is_read']);
    }

    public function test_disabled_listener_does_not_block_normal_ingestion(): void
    {
        $installation = $this->installation(['folders' => ['include' => ['inbox-id']]]);
        $this->vault->setCredentials($installation->id, 'synthetic-access', 'synthetic-refresh', now()->addHour(), ['mailbox_id' => 'directory:account', 'mailbox_path' => '/me', 'microsoft_directory_id' => 'directory', 'microsoft_account_id' => 'account', 'scope' => 'Mail.Read User.Read']);
        $graph = new GraphFixtures;
        $graph->messages['A'] = GraphFixtures::message('A');
        $graph->pages['inbox-id']['baseline'] = ['value' => [['id' => 'A']], '@odata.deltaLink' => GraphFixtures::delta('inbox-id', 'done')];
        $graph->install();
        $listener = new PreparedListener;
        $listener->enabled = false;
        Event::listen('askmydocs.email.prepared.v1', $listener(...));
        $this->ingestion->receipt = $listener->receive(...);
        app(MicrosoftMailConnector::class)->syncFull($installation->id);
        $this->assertSame([], $listener->registered);
        $this->assertCount(1, $this->ingestion->documents);
    }
}
