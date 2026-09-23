<?php

declare(strict_types=1);

namespace Mautic\EmailBundle\Entity;

final class Email
{
    /** @param mixed $content */
    public function __construct(
        private string $fromName,
        private string $subject,
        private $customHtml,
        private $content,
        private int $id = 1,
        private string $name = 'Fixture draft',
        private int $createdBy = 7
    ) {
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getCreatedBy(): int
    {
        return $this->createdBy;
    }

    public function getFromName(): string
    {
        return $this->fromName;
    }

    public function getSubject(): string
    {
        return $this->subject;
    }

    public function setSubject(string $subject): void
    {
        $this->subject = $subject;
    }

    /** @return mixed */
    public function getCustomHtml()
    {
        return $this->customHtml;
    }

    /** @return mixed */
    public function getContent()
    {
        return $this->content;
    }
}