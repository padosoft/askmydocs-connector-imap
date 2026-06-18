<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorImap\Tests\Support;

use Padosoft\AskMyDocsConnectorBase\Contracts\ConnectorIngestionContract;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;

/**
 * Test double for ConnectorIngestionContract.
 *
 * Records dispatchIngestion calls so Feature tests can assert
 * how many documents were handed to the ingest pipeline.
 * resolveKbSourcePath returns disk='local' so Storage::fake('local')
 * is sufficient in tests — no real KbPath prefix is applied.
 */
final class SpyIngestionContract implements ConnectorIngestionContract
{
    /** @var list<array<string,mixed>> */
    public array $dispatches = [];

    public function dispatchIngestion(
        string $projectKey,
        string $relativePath,
        string $disk,
        string $title,
        array $metadata,
        string $mimeType,
        string $tenantId,
    ): void {
        $this->dispatches[] = [
            'projectKey' => $projectKey,
            'relativePath' => $relativePath,
            'disk' => $disk,
            'title' => $title,
            'metadata' => $metadata,
            'mimeType' => $mimeType,
            'tenantId' => $tenantId,
        ];
    }

    /** @return array{relative: string, absolute: string, disk: string} */
    public function resolveKbSourcePath(string $relativePath): array
    {
        $normalised = ltrim(str_replace('\\', '/', $relativePath), '/');

        return [
            'relative' => $normalised,
            'absolute' => $normalised,
            'disk' => 'local',
        ];
    }

    public function redactContent(string $content): string
    {
        return $content;
    }

    public function emitAudit(
        string $connectorKey,
        string $eventType,
        ?int $installationId = null,
        ?array $metadata = null,
    ): void {
        // no-op in tests
    }

    public function softDeleteByRemoteId(
        ConnectorInstallation $installation,
        string $metadataKey,
        string $remoteId,
    ): bool {
        return false;
    }
}
