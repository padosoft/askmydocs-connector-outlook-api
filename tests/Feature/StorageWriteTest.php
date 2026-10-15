<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorMicrosoftMail\Tests\Feature;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Padosoft\AskMyDocsConnectorMicrosoftMail\MicrosoftMailConnector;
use Padosoft\AskMyDocsConnectorMicrosoftMail\Sync\SyncStore;
use Padosoft\AskMyDocsConnectorMicrosoftMail\Tests\Support\GraphFixtures;
use Padosoft\AskMyDocsConnectorMicrosoftMail\Tests\TestCase;

final class StorageWriteTest extends TestCase
{
    public function test_false_storage_put_is_a_failure_before_dispatch_or_cursor_commit(): void
    {
        $installation = $this->installation(['folders' => ['include' => ['inbox-id']]]);
        $this->vault->setCredentials($installation->id, 'synthetic', 'synthetic-refresh', now()->addHour(), ['mailbox_id' => 'directory:account', 'mailbox_path' => '/me', 'microsoft_directory_id' => 'directory', 'microsoft_account_id' => 'account', 'scope' => 'Mail.Read User.Read']);
        $graph = new GraphFixtures;
        $graph->messages['A'] = GraphFixtures::message('A');
        $graph->pages['inbox-id']['baseline'] = ['value' => [['id' => 'A']], '@odata.deltaLink' => GraphFixtures::delta('inbox-id', 'done')];
        $graph->install();
        $prepared = 0;
        Event::listen('askmydocs.email.prepared.v1', static function () use (&$prepared): void {
            $prepared++;
        });
        $disk = Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('put')->once()->withArgs(static fn (string $path, string $bytes): bool => str_starts_with($path, 'sources-prefix/connectors/microsoft-mail/') && str_contains($bytes, 'Complete body'))->andReturn(false);
        Storage::shouldReceive('disk')->with('local')->andReturn($disk);
        try {
            app(MicrosoftMailConnector::class)->syncFull($installation->id);
            $this->fail('False source write was accepted');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Email source write failed', $exception->getMessage());
        }
        $state = (new SyncStore($installation, 'directory:account'))->get('folders', 'inbox-id');
        $this->assertSame(1, $prepared);
        $this->assertSame([], $this->ingestion->documents);
        $this->assertSame(0, $state['page']['index']);
        $this->assertNull($state['delta']);
    }
}
