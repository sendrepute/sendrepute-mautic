<?php

declare(strict_types=1);

require __DIR__.'/stubs/Email.php';
require dirname(__DIR__).'/SendReputeBundle/Service/PreflightException.php';
require dirname(__DIR__).'/SendReputeBundle/Service/ApiClient.php';
require dirname(__DIR__).'/SendReputeBundle/Service/PreflightInput.php';
require dirname(__DIR__).'/SendReputeBundle/Service/SpendLock.php';
require dirname(__DIR__).'/SendReputeBundle/Service/NonceLedger.php';

use Mautic\EmailBundle\Entity\Email;
use MauticPlugin\SendReputeBundle\Service\ApiClient;
use MauticPlugin\SendReputeBundle\Service\PreflightException;
use MauticPlugin\SendReputeBundle\Service\PreflightInput;
use MauticPlugin\SendReputeBundle\Service\SpendLock;
use MauticPlugin\SendReputeBundle\Service\NonceLedger;

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    ++$assertions;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$ledgerDirectory = sys_get_temp_dir().'/sendrepute-mautic-fixture-'.bin2hex(random_bytes(8));
if (!mkdir($ledgerDirectory, 0700)) {
    throw new RuntimeException('Could not create fixture ledger directory.');
}
putenv('SENDREPUTE_MAUTIC_LEDGER_DIR='.$ledgerDirectory);

$draft = new Email(
    'Sender',
    'Hi {contactfield=firstname}',
    '',
    ['slot-a' => '<h1>Hello {contactfield=firstname}</h1>', 'slot-b' => ['<p>Saved body</p>']]
);
$draftInput = PreflightInput::fromEmail($draft);
$assert(
    "<h1>Hello {contactfield=firstname}</h1>\n<p>Saved body</p>" === $draftInput['body'],
    'Builder slots and recipient substitution tokens must remain unchanged.'
);
$assert(['sender', 'subject', 'body'] === array_keys($draftInput), 'Draft extraction must expose only contract fields.');

putenv('SENDREPUTE_MAUTIC_ATOMIC_LOCK_MODE=local-flock');
$lockEmailId = random_int(900000000, 999999999);
$ledgerEmailId = $lockEmailId + 1;
$firstLock = SpendLock::acquire($lockEmailId);
try {
    SpendLock::acquire($lockEmailId);
    throw new RuntimeException('Expected concurrent lock failure.');
} catch (PreflightException $exception) {
    $assert('concurrency' === $exception->category(), 'A concurrent paid intent must fail before transport.');
}
$firstLock->release();
$secondLock = SpendLock::acquire($lockEmailId);
$secondLock->release();

$fixtureNonce = bin2hex(random_bytes(24));
$ledgerLock = SpendLock::acquire($ledgerEmailId);
NonceLedger::consume($ledgerEmailId, 'fixture-session', $fixtureNonce);
$ledgerLock->release();
$staleLock = SpendLock::acquire($ledgerEmailId);
try {
    NonceLedger::consume($ledgerEmailId, 'fixture-session', $fixtureNonce);
    throw new RuntimeException('Expected durable replay rejection.');
} catch (PreflightException $exception) {
    $assert('replay' === $exception->category(), 'Ledger must survive lock release and reject a stale replay.');
} finally {
    $staleLock->release();
}
putenv('SENDREPUTE_MAUTIC_ATOMIC_LOCK_MODE');

$seen = [];
$client = new ApiClient(static function (string $body, string $key) use (&$seen): array {
    $seen = ['body' => json_decode($body, true), 'key' => $key];
    return [
        'status' => 200,
        'body' => json_encode([
            'requestId' => 'offline',
            'model' => 'thor',
            'result' => [
                'label' => 'spam',
                'spamProbability' => 0.75,
                'confidence' => 'high',
                'reasons' => [],
                'flaggedTerms' => [],
                'analyzedFields' => ['sender', 'subject', 'body'],
                'modelVersion' => 'offline-v1',
                'analyzedAt' => '2026-01-01T00:00:00Z',
            ],
            'billing' => ['chargedMillicents' => 1234, 'replayed' => false],
        ], JSON_THROW_ON_ERROR),
    ];
});
$result = $client->classify(['sender' => 'Sender', 'subject' => 'Hi {contactfield=firstname}', 'body' => '<p>Body</p>'], 'secret');
$assert(0.75 === $result['spamProbability'], 'Validated probability should be returned.');
$assert(['sender', 'subject', 'body'] === array_keys($seen['body']), 'Only the three contract fields may be transmitted.');
$assert('Hi {contactfield=firstname}' === $seen['body']['subject'], 'Mautic substitution tokens must be preserved.');

$categories = [
    400 => 'malformed',
    401 => 'auth',
    402 => 'balance',
    403 => 'auth',
    429 => 'rate_limit',
    503 => 'transport',
];
foreach ($categories as $status => $category) {
    $failing = new ApiClient(static fn (): array => ['status' => $status, 'body' => '{"error":{"message":"ignored"}}']);
    try {
        $failing->classify(['sender' => 'S', 'subject' => 'X', 'body' => 'Y'], 'secret');
        throw new RuntimeException('Expected status failure.');
    } catch (PreflightException $exception) {
        $assert($category === $exception->category(), 'Status '.$status.' category mismatch.');
        $assert(false === str_contains($exception->getMessage(), 'secret'), 'Secrets must not appear in errors.');
    }
}

foreach ([-0.1, 1.1, INF, NAN, '0.5'] as $invalidScore) {
    $invalid = new ApiClient(static fn (): array => [
        'status' => 200,
        'body' => json_encode([
            'requestId' => 'offline',
            'model' => 'thor',
            'result' => [
                'label' => 'inbox',
                'spamProbability' => $invalidScore,
                'confidence' => 'low',
                'reasons' => [],
                'flaggedTerms' => [],
                'analyzedFields' => ['body'],
                'modelVersion' => 'offline-v1',
                'analyzedAt' => '2026-01-01T00:00:00Z',
            ],
            'billing' => ['chargedMillicents' => 0, 'replayed' => true],
        ]),
    ]);
    try {
        $invalid->classify(['sender' => 'S', 'subject' => 'X', 'body' => 'Y'], 'secret');
        throw new RuntimeException('Expected invalid score failure.');
    } catch (PreflightException $exception) {
        $assert('malformed_response' === $exception->category(), 'Invalid scores must fail closed.');
    }
}

$malformedBodies = [
    '',
    '{',
    '[]',
    '{"requestId":"x","model":"thor","result":{},"billing":{}}',
    json_encode([
        'requestId' => 'x',
        'model' => 'thor',
        'result' => [
            'label' => 'inbox',
            'spamProbability' => 0.1,
            'confidence' => 'low',
            'reasons' => [],
            'flaggedTerms' => [],
            'analyzedFields' => [],
            'modelVersion' => 'v1',
            'analyzedAt' => 'tomorrow',
            'undeclared' => true,
        ],
        'billing' => ['chargedMillicents' => 0, 'replayed' => true],
    ], JSON_THROW_ON_ERROR),
    json_encode([
        'requestId' => 'x',
        'model' => 'thor',
        'result' => [
            'label' => 'inbox',
            'spamProbability' => 0.1,
            'confidence' => 'low',
            'reasons' => [],
            'flaggedTerms' => [],
            'analyzedFields' => [],
            'modelVersion' => 'v1',
            'analyzedAt' => '2026-01-01T00:00:00Z',
            'contentAudit' => ['score' => 101],
        ],
        'billing' => ['chargedMillicents' => 0, 'replayed' => true],
    ], JSON_THROW_ON_ERROR),
];
foreach ($malformedBodies as $malformedBody) {
    $malformed = new ApiClient(static fn (): array => ['status' => 200, 'body' => $malformedBody]);
    try {
        $malformed->classify(['sender' => 'S', 'subject' => 'X', 'body' => 'Y'], 'secret');
        throw new RuntimeException('Expected malformed body failure.');
    } catch (PreflightException $exception) {
        $assert('malformed_response' === $exception->category(), 'Malformed response bodies must fail closed.');
    }
}

$redirect = new ApiClient(static fn (): array => ['status' => 302, 'body' => '']);
try {
    $redirect->classify(['sender' => 'S', 'subject' => 'X', 'body' => 'Y'], 'secret');
    throw new RuntimeException('Expected redirect failure.');
} catch (PreflightException $exception) {
    $assert('transport' === $exception->category(), 'Redirects must not produce a spam decision.');
}

echo "OK ({$assertions} assertions)\n";

foreach (glob($ledgerDirectory.'/*') ?: [] as $fixtureLedgerFile) {
    unlink($fixtureLedgerFile);
}
rmdir($ledgerDirectory);
putenv('SENDREPUTE_MAUTIC_LEDGER_DIR');