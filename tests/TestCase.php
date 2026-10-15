<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorMicrosoftMail\Tests;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Orchestra\Testbench\TestCase as Orchestra;
use Padosoft\AskMyDocsConnectorBase\Auth\OAuthCredentialVault;
use Padosoft\AskMyDocsConnectorBase\ConnectorServiceProvider;
use Padosoft\AskMyDocsConnectorBase\Contracts\ConnectorIngestionContract;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;
use Padosoft\AskMyDocsConnectorBase\Support\TenantContext;
use Padosoft\AskMyDocsConnectorMicrosoftMail\MicrosoftMailServiceProvider;
use Padosoft\AskMyDocsConnectorMicrosoftMail\Tests\Support\FakeIngestion;

abstract class TestCase extends Orchestra
{
    public FakeIngestion $ingestion;

    public OAuthCredentialVault $vault;

    protected function getPackageProviders($app): array
    {
        return [ConnectorServiceProvider::class, MicrosoftMailServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $app['config']->set('cache.default', 'array');
        $app['config']->set('connector-microsoft-mail.client_id', 'test-client');
        $app['config']->set('connector-microsoft-mail.client_secret', 'synthetic-secret');
        $app['config']->set('connector-microsoft-mail.redirect_uri', 'https://askmydocsdev.test/connectors/microsoft-mail/oauth/callback');
        $app['config']->set('connector-microsoft-mail.http.retries', 0);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate')->run();
        app(TenantContext::class)->set('tenant-a');
        $this->ingestion = new FakeIngestion;
        $this->app->instance(ConnectorIngestionContract::class, $this->ingestion);
        $this->vault = app(OAuthCredentialVault::class);
        Storage::fake('local');
        Http::preventStrayRequests();
    }

    public function installation(array $config = [], string $tenant = 'tenant-a', string $project = 'mail'): ConnectorInstallation
    {
        return ConnectorInstallation::query()->create(['tenant_id' => $tenant, 'connector_name' => 'microsoft-mail', 'label' => 'account-'.bin2hex(random_bytes(4)), 'project_key' => $project, 'status' => 'active', 'config_json' => $config]);
    }
}
