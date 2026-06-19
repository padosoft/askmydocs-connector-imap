<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorImap\Support;

use Padosoft\AskMyDocsConnectorBase\Support\Metadata\SourceAwareMetadataBuilder;
use Padosoft\AskMyDocsConnectorImap\Imap\ImapMessage;

final class MailMetadata
{
    /** @return array<string,mixed> */
    public function build(int $installationId, ImapMessage $m): array
    {
        $sourceFields = [
            'mailbox' => $m->mailbox,
            'uid' => $m->uid,
            'uidvalidity' => $m->uidValidity,
            'message_id' => $m->messageId,
            'in_reply_to' => $m->inReplyTo,
            'references' => $m->references,
            'from_name' => $m->fromName,
            'from_email' => $m->fromEmail,
            'to' => $m->to,
            'cc' => $m->cc,
            'date' => $m->date?->toIso8601String(),
            'subject' => $m->subject,
            'flags' => $m->flags,
            'labels' => $m->labels,
            'has_attachments' => $m->hasAttachments(),
            'attachment_count' => count($m->attachments),
        ];

        return (new SourceAwareMetadataBuilder)->build(
            base: [
                'connector' => 'imap',
                'installation_id' => $installationId,
                'imap_uid' => (string) $m->uid,
                'imap_doc_key' => $m->mailbox.':'.$m->uidValidity.':'.$m->uid,
                'imap_mailbox' => $m->mailbox,
                'imap_message_id' => $m->messageId,
            ],
            sourceKey: 'imap',
            sourceFields: $sourceFields,
            tags: array_values(array_unique(array_merge($m->labels, [$m->mailbox]))),
            statusActive: true,
            lastModified: $m->date?->toIso8601String(),
            owner: $m->fromEmail,
        );
    }
}
