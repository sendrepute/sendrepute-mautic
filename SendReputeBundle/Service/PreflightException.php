<?php

declare(strict_types=1);

namespace MauticPlugin\SendReputeBundle\Service;

final class PreflightException extends \RuntimeException
{
    public function __construct(
        private string $category,
        string $safeMessage
    ) {
        parent::__construct($safeMessage);
    }

    public function category(): string
    {
        return $this->category;
    }
}