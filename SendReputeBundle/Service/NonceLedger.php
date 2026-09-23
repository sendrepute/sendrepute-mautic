<?php

declare(strict_types=1);

namespace MauticPlugin\SendReputeBundle\Service;

/**
 * Durable, content-free replay ledger for paid intents on one web node.
 *
 * Callers must hold SpendLock for the email for the entire read/write sequence
 * and until the paid HTTP request completes.
 */
final class NonceLedger
{
    private const VERSION = 1;
    private const MAX_ENTRIES = 4096;
    private const RETENTION_SECONDS = 86400;

    public static function consume(int $emailId, string $sessionId, string $nonce): void
    {
        if ('' === $sessionId || !preg_match('/^[a-f0-9]{48}$/D', $nonce)) {
            throw new PreflightException('replay', 'The paid-analysis intent is invalid or already consumed.');
        }

        $directory = self::directory();
        $path = $directory.DIRECTORY_SEPARATOR.hash('sha256', (string) $emailId).'.json';
        if (is_link($path)) {
            throw new PreflightException('configuration', 'The paid-intent ledger path is unsafe.');
        }

        $entries = [];
        if (is_file($path)) {
            $raw = @file_get_contents($path);
            $decoded = is_string($raw) ? json_decode($raw, true) : null;
            if (!is_array($decoded)
                || ($decoded['version'] ?? null) !== self::VERSION
                || !isset($decoded['entries'])
                || !is_array($decoded['entries'])
                || count($decoded) !== 2
            ) {
                throw new PreflightException('configuration', 'The paid-intent ledger is unreadable or malformed.');
            }
            $entries = $decoded['entries'];
        }

        $now = time();
        foreach ($entries as $digest => $expiresAt) {
            if (!is_string($digest)
                || !preg_match('/^[a-f0-9]{64}$/D', $digest)
                || !is_int($expiresAt)
            ) {
                throw new PreflightException('configuration', 'The paid-intent ledger is malformed.');
            }
            if ($expiresAt <= $now) {
                unset($entries[$digest]);
            }
        }

        // Session IDs and nonces never appear in the ledger, only their
        // one-way composite digest and expiry.
        $key = hash('sha256', $sessionId."\0".$nonce);
        if (isset($entries[$key])) {
            throw new PreflightException('replay', 'The paid-analysis intent is invalid or already consumed.');
        }
        if (count($entries) >= self::MAX_ENTRIES) {
            throw new PreflightException('configuration', 'The paid-intent ledger is full; no request was attempted.');
        }

        $entries[$key] = $now + self::RETENTION_SECONDS;
        asort($entries, SORT_NUMERIC);
        self::persist($path, ['version' => self::VERSION, 'entries' => $entries]);
    }

    private static function directory(): string
    {
        $configured = getenv('SENDREPUTE_MAUTIC_LEDGER_DIR');
        if (!is_string($configured) || '' === $configured || !str_starts_with($configured, DIRECTORY_SEPARATOR)) {
            throw new PreflightException('configuration', 'A persistent absolute paid-intent ledger directory is required.');
        }
        $configured = rtrim($configured, DIRECTORY_SEPARATOR);
        $directory = realpath($configured);
        if (false === $directory || !is_dir($directory) || is_link($configured)) {
            throw new PreflightException('configuration', 'The paid-intent ledger directory is unsafe.');
        }
        if (!is_writable($directory)) {
            throw new PreflightException('configuration', 'The paid-intent ledger directory is not writable.');
        }
        $permissions = @fileperms($directory);
        if (false === $permissions || 0 !== ($permissions & 0077)) {
            throw new PreflightException('configuration', 'The paid-intent ledger directory must have mode 0700.');
        }
        if (function_exists('posix_geteuid')) {
            $owner = @fileowner($directory);
            if (false === $owner || $owner !== posix_geteuid()) {
                throw new PreflightException('configuration', 'The paid-intent ledger directory has an unsafe owner.');
            }
        }

        return $directory;
    }

    /** @param array{version:int,entries:array<string,int>} $value */
    private static function persist(string $path, array $value): void
    {
        $json = json_encode($value, JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw new PreflightException('configuration', 'The paid-intent ledger could not be encoded.');
        }

        $temporary = $path.'.'.bin2hex(random_bytes(12)).'.tmp';
        $handle = @fopen($temporary, 'x+b');
        if (false === $handle) {
            throw new PreflightException('configuration', 'The paid-intent ledger could not be created.');
        }
        @chmod($temporary, 0600);

        $written = false;
        try {
            $written = strlen($json) === fwrite($handle, $json)
                && fflush($handle)
                && (!function_exists('fsync') || fsync($handle));
        } finally {
            fclose($handle);
        }
        if (!$written || !@rename($temporary, $path)) {
            @unlink($temporary);
            throw new PreflightException('configuration', 'The paid-intent ledger could not be durably persisted.');
        }
        @chmod($path, 0600);

        // Reopen and flush the authoritative path, then its parent directory so
        // both file data and rename metadata are durable before paid HTTP.
        $persisted = @fopen($path, 'rb');
        if (false === $persisted) {
            throw new PreflightException('configuration', 'The paid-intent ledger could not be verified.');
        }
        try {
            if (function_exists('fsync') && !fsync($persisted)) {
                throw new PreflightException('configuration', 'The paid-intent ledger could not be durably flushed.');
            }
        } finally {
            fclose($persisted);
        }

        if (function_exists('fsync')) {
            $directory = @fopen(dirname($path), 'r');
            if (false === $directory) {
                throw new PreflightException('configuration', 'The paid-intent ledger directory could not be flushed.');
            }
            try {
                if (!fsync($directory)) {
                    throw new PreflightException('configuration', 'The paid-intent ledger directory could not be durably flushed.');
                }
            } finally {
                fclose($directory);
            }
        }
    }
}