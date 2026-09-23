<?php

declare(strict_types=1);

namespace MauticPlugin\SendReputeBundle\Service;

use Mautic\EmailBundle\Entity\Email;

final class PreflightInput
{
    public const MAX_SENDER_BYTES = 320;
    public const MAX_SUBJECT_BYTES = 998;
    public const MAX_BODY_BYTES = 524288;

    /**
     * @return array{sender:string,subject:string,body:string}
     */
    public static function fromEmail(Email $email): array
    {
        $sender = trim((string) $email->getFromName());
        $subject = (string) $email->getSubject();
        $customHtml = $email->getCustomHtml();
        $body = is_string($customHtml) && '' !== $customHtml
            ? $customHtml
            : self::flattenContent($email->getContent());

        if ('' === $sender) {
            throw new PreflightException('malformed', 'The draft requires a sender display name.');
        }
        if ('' === trim($subject)) {
            throw new PreflightException('malformed', 'The draft requires a subject.');
        }
        if ('' === $body) {
            throw new PreflightException('malformed', 'The draft requires email body content.');
        }
        if (strlen($sender) > self::MAX_SENDER_BYTES
            || strlen($subject) > self::MAX_SUBJECT_BYTES
            || strlen($body) > self::MAX_BODY_BYTES
        ) {
            throw new PreflightException('malformed', 'The sender, subject, or body exceeds the supported API limit.');
        }

        return ['sender' => $sender, 'subject' => $subject, 'body' => $body];
    }

    /**
     * Keep Mautic tokens exactly as authored. Keys are not included because they
     * are builder metadata rather than message content.
     *
     * @param mixed $content
     */
    private static function flattenContent($content): string
    {
        if (is_string($content)) {
            return $content;
        }
        if (!is_array($content)) {
            return '';
        }

        $parts = [];
        array_walk_recursive($content, static function ($value) use (&$parts): void {
            if (is_string($value) && '' !== $value) {
                $parts[] = $value;
            }
        });

        return implode("\n", $parts);
    }
}