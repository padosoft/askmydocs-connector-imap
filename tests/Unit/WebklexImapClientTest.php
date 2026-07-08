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
use Webklex\PHPIMAP\Exceptions\ResponseException;

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

    /**
     * webklex reports a server `NO`/`BAD` to the auth exchange as a
     * ResponseException (thrown by authenticate()->validatedData()), NOT an
     * AuthFailedException — this is the real Exchange Online app-only failure
     * ("NO AUTHENTICATE failed"). It must still be a permanent AUTH failure so
     * the host re-prompts instead of retrying a doomed connection forever.
     */
    public function test_rejected_auth_response_surfaces_as_connector_auth_exception(): void
    {
        $client = Mockery::mock(Client::class);
        $client->shouldReceive('connect')->once()->andThrow(new ResponseException(
            "Command failed to process:\nCauses:\n\t- got failure response: NO AUTHENTICATE failed.\r\n\nError occurred"
        ));

        $this->expectException(ConnectorAuthException::class);
        (new WebklexImapClient($client))->listMailboxes();
    }

    /**
     * A ResponseException that is not an auth rejection (some other command
     * getting a NO/BAD during the handshake) stays a transient API failure —
     * we must not over-classify every driver response error as permanent auth.
     */
    public function test_non_auth_response_failure_stays_connector_api_exception(): void
    {
        $client = Mockery::mock(Client::class);
        $client->shouldReceive('connect')->once()->andThrow(new ResponseException(
            "Command failed to process:\nCauses:\n\t- got failure response: NO server busy, try again.\r\n\nError occurred"
        ));

        $this->expectException(ConnectorApiException::class);
        (new WebklexImapClient($client))->listMailboxes();
    }
}
