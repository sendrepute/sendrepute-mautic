<?php

declare(strict_types=1);

require __DIR__.'/stubs/Framework.php';
require __DIR__.'/stubs/Email.php';
require dirname(__DIR__).'/SendReputeBundle/Service/PreflightException.php';
require dirname(__DIR__).'/SendReputeBundle/Service/PreflightInput.php';
require dirname(__DIR__).'/SendReputeBundle/Service/SpendLock.php';
require dirname(__DIR__).'/SendReputeBundle/Service/NonceLedger.php';
require dirname(__DIR__).'/SendReputeBundle/Service/ApiClient.php';
require dirname(__DIR__).'/SendReputeBundle/Controller/PreflightController.php';

use Mautic\CoreBundle\Security\Permissions\CorePermissions;
use Mautic\EmailBundle\Entity\Email;
use Mautic\EmailBundle\Model\EmailModel;
use MauticPlugin\SendReputeBundle\Controller\PreflightController;
use MauticPlugin\SendReputeBundle\Service\NonceLedger;
use MauticPlugin\SendReputeBundle\Service\SpendLock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\TestSession;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class FixtureCsrfManager implements CsrfTokenManagerInterface
{
    public function getToken(string $tokenId): CsrfToken
    {
        return new CsrfToken($tokenId, 'valid-csrf');
    }

    public function refreshToken(string $tokenId): CsrfToken
    {
        return $this->getToken($tokenId);
    }

    public function removeToken(string $tokenId): ?CsrfToken
    {
        return null;
    }

    public function isTokenValid(CsrfToken $token): bool
    {
        return 'valid-csrf' === $token->getValue();
    }
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    ++$assertions;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

putenv('SENDREPUTE_MAUTIC_PAID_ANALYSIS_ENABLED=1');
putenv('SENDREPUTE_MAUTIC_ATOMIC_LOCK_MODE=local-flock');
$ledgerDirectory = sys_get_temp_dir().'/sendrepute-mautic-controller-'.bin2hex(random_bytes(8));
if (!mkdir($ledgerDirectory, 0700)) {
    throw new RuntimeException('Could not create controller fixture ledger directory.');
}
putenv('SENDREPUTE_MAUTIC_LEDGER_DIR='.$ledgerDirectory);

$routeConfig = require dirname(__DIR__).'/SendReputeBundle/Config/config.php';
$route = $routeConfig['routes']['main']['sendrepute_email_preflight'] ?? null;
$assert(is_array($route), 'Preflight route must be registered.');
$assert(['GET', 'POST'] === $route['method'], 'Route must permit only GET and POST.');
$assert('\d+' === $route['requirements']['emailId'], 'Route must constrain email IDs to digits.');

$controller = new PreflightController();
$csrf = new FixtureCsrfManager();
$email = new Email('Sender', 'Subject', '<p>Body</p>', [], 42, 'Selected draft', 7);
$model = new EmailModel($email);

$denied = $controller->preflightAction(
    new Request('GET', new TestSession()),
    $model,
    new CorePermissions(false, true),
    $csrf,
    42
);
$assert(403 === $denied->getStatusCode(), 'A non-admin with resource view access must be denied.');

$resourceDenied = $controller->preflightAction(
    new Request('GET', new TestSession()),
    $model,
    new CorePermissions(true, false),
    $csrf,
    42
);
$assert(403 === $resourceDenied->getStatusCode(), 'An admin without resource access must be denied.');

$session = new TestSession();
$get = $controller->preflightAction(
    new Request('GET', $session),
    $model,
    new CorePermissions(true, true),
    $csrf,
    42
);
$assert(200 === $get->getStatusCode(), 'Authorized GET should render confirmation.');
preg_match('/name="intent" value="([a-f0-9]+)"/', $get->getContent(), $match);
$intent = $match[1] ?? '';
$assert(48 === strlen($intent), 'GET must issue a 192-bit one-use intent.');

$badCsrf = $controller->preflightAction(
    new Request('POST', $session, [
        '_token' => 'invalid',
        'intent' => $intent,
        'paid_confirmation' => 'yes',
    ]),
    $model,
    new CorePermissions(true, true),
    $csrf,
    42
);
$assert(str_contains($badCsrf->getContent(), 'expired or the draft changed'), 'Invalid CSRF must prevent analysis.');

$replay = $controller->preflightAction(
    new Request('POST', $session, [
        '_token' => 'valid-csrf',
        'intent' => $intent,
        'paid_confirmation' => 'yes',
    ]),
    $model,
    new CorePermissions(true, true),
    $csrf,
    42
);
$assert(str_contains($replay->getContent(), 'expired or the draft changed'), 'A consumed intent must reject replay.');

$staleSession = new TestSession('stale-snapshot-session');
$staleGet = $controller->preflightAction(
    new Request('GET', $staleSession),
    $model,
    new CorePermissions(true, true),
    $csrf,
    42
);
preg_match('/name="intent" value="([a-f0-9]+)"/', $staleGet->getContent(), $staleMatch);
$staleNonce = $staleMatch[1] ?? '';
$ledgerLock = SpendLock::acquire(42);
try {
    NonceLedger::consume(42, $staleSession->getId(), $staleNonce);
} finally {
    $ledgerLock->release();
}
// The session intentionally still contains its pre-consumption snapshot. This
// models a stale handler save becoming visible only after the first lock release.
$staleReplay = $controller->preflightAction(
    new Request('POST', $staleSession, [
        '_token' => 'valid-csrf',
        'intent' => $staleNonce,
        'paid_confirmation' => 'yes',
    ]),
    $model,
    new CorePermissions(true, true),
    $csrf,
    42
);
$assert(str_contains($staleReplay->getContent(), 'already consumed'), 'Durable ledger must reject a stale resurrected session snapshot.');

$independentEmailId = random_int(800000000, 899999999);
$sharedNonce = bin2hex(random_bytes(24));
$independentLock = SpendLock::acquire($independentEmailId);
try {
    NonceLedger::consume($independentEmailId, 'independent-session-a', $sharedNonce);
    NonceLedger::consume($independentEmailId, 'independent-session-b', $sharedNonce);
    $assert(true, 'Independent sessions may own distinct intents with the same nonce bytes.');
} finally {
    $independentLock->release();
}

$changedSession = new TestSession();
$changedGet = $controller->preflightAction(
    new Request('GET', $changedSession),
    $model,
    new CorePermissions(true, true),
    $csrf,
    42
);
preg_match('/name="intent" value="([a-f0-9]+)"/', $changedGet->getContent(), $changedMatch);
$email->setSubject('Changed after confirmation page');
$changed = $controller->preflightAction(
    new Request('POST', $changedSession, [
        '_token' => 'valid-csrf',
        'intent' => $changedMatch[1] ?? '',
        'paid_confirmation' => 'yes',
    ]),
    $model,
    new CorePermissions(true, true),
    $csrf,
    42
);
$assert(str_contains($changed->getContent(), 'expired or the draft changed'), 'Changed content must invalidate the intent.');

putenv('SENDREPUTE_MAUTIC_PAID_ANALYSIS_ENABLED');
putenv('SENDREPUTE_MAUTIC_ATOMIC_LOCK_MODE');
putenv('SENDREPUTE_MAUTIC_LEDGER_DIR');
foreach (glob($ledgerDirectory.'/*') ?: [] as $fixtureLedgerFile) {
    unlink($fixtureLedgerFile);
}
rmdir($ledgerDirectory);

echo "OK ({$assertions} controller assertions)\n";