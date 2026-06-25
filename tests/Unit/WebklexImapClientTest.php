<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorImap\Tests\Unit;

use Mockery;
use Padosoft\AskMyDocsConnectorBase\Exceptions\ConnectorApiException;
use Padosoft\AskMyDocsConnectorBase\Exceptions\ConnectorAuthException;
use Padosoft\AskMyDocsConnectorImap\Imap\WebklexImapClient;
use Padosoft\AskMyDocsConnectorImap\Tests\TestCase;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\Exceptions\AuthFailedException;
use Webklex\PHPIMAP\Exceptions\ConnectionFailedException;

/**
 * The connect() failure taxonomy: a rejected login is an AUTH failure (host
 * prompts re-auth, sync job stops retrying), every other connect error is a
 * transient API failure (retryable). This is what lets folder discovery
 * (SupportsFolderDiscovery) report the right error to the operator.
 */
final class WebklexImapClientTest extends TestCase
{
    public function test_rejected_login_surfaces_as_connector_auth_exception(): void
    {
        $client = Mockery::mock(Client::class);
        $client->shouldReceive('connect')->once()->andThrow(new AuthFailedException('login rejected'));

        $this->expectException(ConnectorAuthException::class);
        (new WebklexImapClient($client))->listMailboxes();
    }

    public function test_connection_failure_surfaces_as_connector_api_exception(): void
    {
        $client = Mockery::mock(Client::class);
        $client->shouldReceive('connect')->once()->andThrow(new ConnectionFailedException('host unreachable'));

        $this->expectException(ConnectorApiException::class);
        (new WebklexImapClient($client))->listMailboxes();
    }
}
