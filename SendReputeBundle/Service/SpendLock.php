<?php

declare(strict_types=1);

namespace MauticPlugin\SendReputeBundle\Service;

/**
 * A process-safe lock for one email on a single Mautic web node.
 *
 * Multi-node deployments must not enable local-flock mode. PHP flock has no
 * cross-host guarantee even when a network filesystem appears to support it.
 */
final class SpendLock
{
    /** @var resource|null */
    private $handle;

    public static function acquire(int $emailId): self
    {
        if ('local-flock' !== getenv('SENDREPUTE_MAUTIC_ATOMIC_LOCK_MODE')) {
            throw new PreflightException(
                'configuration',
                'Paid analysis requires an explicitly configured atomic lock mode; multi-node deployments are unsupported.'
            );
        }

        $path = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
            .DIRECTORY_SEPARATOR.'sendrepute-mautic-email-'.hash('sha256', (string) $emailId).'.lock';
        $handle = @fopen($path, 'c');
        if (false === $handle || !@flock($handle, LOCK_EX | LOCK_NB)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw new PreflightException(
                'concurrency',
                'Another paid preflight for this draft is in progress. No request was attempted.'
            );
        }
        @chmod($path, 0600);

        $lock = new self();
        $lock->handle = $handle;

        return $lock;
    }

    public function release(): void
    {
        if (is_resource($this->handle)) {
            flock($this->handle, LOCK_UN);
            fclose($this->handle);
            $this->handle = null;
        }
    }

    public function __destruct()
    {
        $this->release();
    }
}