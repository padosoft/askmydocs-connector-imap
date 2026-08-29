<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorImap\Tests\Unit;

use Padosoft\AskMyDocsConnectorBase\Contracts\DeclaresProvenance;
use Padosoft\AskMyDocsConnectorBase\ProvenanceTier;
use Padosoft\AskMyDocsConnectorImap\ImapConnector;
use Padosoft\AskMyDocsConnectorImap\Tests\TestCase;

final class ProvenanceDeclarationTest extends TestCase
{
    public function test_connector_declares_provenance(): void
    {
        $this->assertInstanceOf(DeclaresProvenance::class, $this->app->make(ImapConnector::class));
    }

    public function test_mail_is_untrusted_external(): void
    {
        // Every other connector reads a system the organisation administers,
        // so its content was written by someone granted the ability to write
        // it. A mailbox accepts a message from anyone who knows the address,
        // and delivery proves nothing about the sender's authority.
        $tier = $this->app->make(ImapConnector::class)->provenanceTier(1);

        $this->assertSame(ProvenanceTier::UntrustedExternal, $tier);
        $this->assertTrue($tier->isExternallyAuthored());
    }

    public function test_the_tier_does_not_soften_for_any_installation(): void
    {
        // A folder allow-list narrows WHICH mail is ingested, never who was
        // able to send it. If the tier could be derived from configuration,
        // an operator syncing only an internal-looking folder would silently
        // mark external mail trusted — the exact mistake this label exists to
        // prevent. Pinning it here stops a later "smart" refinement from
        // reintroducing that path unnoticed.
        $connector = $this->app->make(ImapConnector::class);

        foreach ([1, 2, 99, PHP_INT_MAX] as $installationId) {
            $this->assertSame(
                ProvenanceTier::UntrustedExternal,
                $connector->provenanceTier($installationId),
                "Installation {$installationId} must not be treated as internally authored.",
            );
        }
    }
}
