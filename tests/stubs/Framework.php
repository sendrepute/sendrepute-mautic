<?php

declare(strict_types=1);

namespace Symfony\Bundle\FrameworkBundle\Controller {
    abstract class AbstractController
    {
    }
}

namespace Symfony\Component\HttpFoundation {
    final class ParameterBag
    {
        /** @param array<string,mixed> $values */
        public function __construct(private array $values = [])
        {
        }

        /** @param mixed $default
         *  @return mixed
         */
        public function get(string $key, $default = null)
        {
            return $this->values[$key] ?? $default;
        }
    }

    final class TestSession
    {
        /** @var array<string,mixed> */
        private array $values = [];

        public function __construct(private string $id = 'fixture-session')
        {
        }

        public function getId(): string
        {
            return $this->id;
        }

        /** @param mixed $value */
        public function set(string $key, $value): void
        {
            $this->values[$key] = $value;
        }

        /** @return mixed */
        public function get(string $key)
        {
            return $this->values[$key] ?? null;
        }

        public function remove(string $key): void
        {
            unset($this->values[$key]);
        }
    }

    class Request
    {
        public ParameterBag $request;

        /** @param array<string,mixed> $post */
        public function __construct(
            private string $method,
            private TestSession $session,
            array $post = []
        ) {
            $this->request = new ParameterBag($post);
        }

        public function isMethod(string $method): bool
        {
            return 0 === strcasecmp($this->method, $method);
        }

        public function getSession(): TestSession
        {
            return $this->session;
        }
    }

    class Response
    {
        public const HTTP_OK = 200;
        public const HTTP_FORBIDDEN = 403;
        public const HTTP_NOT_FOUND = 404;

        /** @param array<string,string> $headers */
        public function __construct(
            private string $content = '',
            private int $status = 200,
            array $headers = []
        ) {
        }

        public function getStatusCode(): int
        {
            return $this->status;
        }

        public function getContent(): string
        {
            return $this->content;
        }
    }
}

namespace Symfony\Component\Security\Csrf {
    class CsrfToken
    {
        public function __construct(private string $id, private string $value)
        {
        }

        public function getValue(): string
        {
            return $this->value;
        }
    }

    interface CsrfTokenManagerInterface
    {
        public function getToken(string $tokenId): CsrfToken;

        public function refreshToken(string $tokenId): CsrfToken;

        public function removeToken(string $tokenId): ?CsrfToken;

        public function isTokenValid(CsrfToken $token): bool;
    }
}

namespace Mautic\EmailBundle\Model {
    use Mautic\EmailBundle\Entity\Email;

    class EmailModel
    {
        public function __construct(private ?Email $email)
        {
        }

        public function getEntity($id = null): ?Email
        {
            return $this->email;
        }
    }
}

namespace Mautic\CoreBundle\Security\Permissions {
    class CorePermissions
    {
        public function __construct(private bool $admin, private bool $entityAccess)
        {
        }

        public function isAdmin(): bool
        {
            return $this->admin;
        }

        public function hasEntityAccess($ownPermission, $otherPermission, $ownerId = 0): bool
        {
            return $this->entityAccess;
        }
    }
}