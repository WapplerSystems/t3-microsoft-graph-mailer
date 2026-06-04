<?php

declare(strict_types=1);

namespace WapplerSystems\MicrosoftGraphMailer\Mailer;

use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Header\UnstructuredHeader;
use Symfony\Component\Mime\Part\DataPart;

/**
 * Converts a Symfony Email into the JSON payload accepted by
 * Microsoft Graph /users/{id}/sendMail.
 */
final class EmailToGraphPayloadMapper
{
    /**
     * @return array<string, mixed>
     */
    public function map(Email $email): array
    {
        $message = [
            'subject' => (string)$email->getSubject(),
            'body' => $this->mapBody($email),
            'toRecipients' => $this->mapAddresses($email->getTo()),
        ];

        $from = $email->getFrom();
        if ($from !== []) {
            $message['from'] = $this->mapAddresses([$from[0]])[0];
        }

        if ($email->getCc() !== []) {
            $message['ccRecipients'] = $this->mapAddresses($email->getCc());
        }
        if ($email->getBcc() !== []) {
            $message['bccRecipients'] = $this->mapAddresses($email->getBcc());
        }
        if ($email->getReplyTo() !== []) {
            $message['replyTo'] = $this->mapAddresses($email->getReplyTo());
        }

        $attachments = $this->mapAttachments($email);
        if ($attachments !== []) {
            $message['attachments'] = $attachments;
        }

        $internetHeaders = $this->mapInternetMessageHeaders($email);
        if ($internetHeaders !== []) {
            $message['internetMessageHeaders'] = $internetHeaders;
        }

        return [
            'message' => $message,
            'saveToSentItems' => false,
        ];
    }

    /**
     * @return array{contentType: 'HTML'|'Text', content: string}
     */
    private function mapBody(Email $email): array
    {
        $html = $email->getHtmlBody();
        if (is_string($html) && $html !== '') {
            return ['contentType' => 'HTML', 'content' => $html];
        }
        return ['contentType' => 'Text', 'content' => (string)$email->getTextBody()];
    }

    /**
     * @param Address[] $addresses
     * @return array<int, array{emailAddress: array{address: string, name?: string}}>
     */
    private function mapAddresses(array $addresses): array
    {
        $out = [];
        foreach ($addresses as $address) {
            $entry = ['address' => $address->getAddress()];
            $name = $address->getName();
            if ($name !== '') {
                $entry['name'] = $name;
            }
            $out[] = ['emailAddress' => $entry];
        }
        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function mapAttachments(Email $email): array
    {
        $out = [];
        foreach ($email->getAttachments() as $part) {
            if (!$part instanceof DataPart) {
                continue;
            }
            $body = $part->getBody();
            $headers = $part->getPreparedHeaders();
            $disposition = $headers->get('content-disposition');
            $isInline = $disposition !== null && str_starts_with(strtolower((string)$disposition->getBodyAsString()), 'inline');

            $attachment = [
                '@odata.type' => '#microsoft.graph.fileAttachment',
                'name' => $part->getFilename() ?? 'attachment',
                'contentType' => $part->getMediaType() . '/' . $part->getMediaSubtype(),
                'contentBytes' => base64_encode($body),
                'isInline' => $isInline,
            ];

            $contentId = $part->getContentId();
            if ($contentId !== null && $contentId !== '') {
                $attachment['contentId'] = $contentId;
            }

            $out[] = $attachment;
        }
        return $out;
    }

    /**
     * Graph only accepts `x-`-prefixed custom headers via internetMessageHeaders.
     * Standard MIME headers (To, From, Subject, …) are derived from message fields.
     *
     * @return list<array{name: string, value: string}>
     */
    private function mapInternetMessageHeaders(Email $email): array
    {
        $reserved = [
            'from', 'to', 'cc', 'bcc', 'reply-to', 'subject', 'date',
            'content-type', 'content-transfer-encoding', 'mime-version',
            'message-id', 'sender', 'return-path',
        ];

        $out = [];
        foreach ($email->getHeaders()->all() as $header) {
            $name = strtolower($header->getName());
            if (in_array($name, $reserved, true)) {
                continue;
            }
            if (!str_starts_with($name, 'x-')) {
                continue;
            }
            if (!$header instanceof UnstructuredHeader) {
                continue;
            }
            $out[] = [
                'name' => $header->getName(),
                'value' => $header->getBodyAsString(),
            ];
        }
        return $out;
    }
}
