<?php

declare(strict_types=1);

namespace MauticPlugin\SendReputeBundle\Controller;

use Mautic\CoreBundle\Security\Permissions\CorePermissions;
use Mautic\EmailBundle\Model\EmailModel;
use MauticPlugin\SendReputeBundle\Service\ApiClient;
use MauticPlugin\SendReputeBundle\Service\NonceLedger;
use MauticPlugin\SendReputeBundle\Service\PreflightException;
use MauticPlugin\SendReputeBundle\Service\PreflightInput;
use MauticPlugin\SendReputeBundle\Service\SpendLock;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class PreflightController extends AbstractController
{
    public function preflightAction(
        Request $request,
        EmailModel $emailModel,
        CorePermissions $security,
        CsrfTokenManagerInterface $csrf,
        int $emailId
    ): Response {
        $email = $emailModel->getEntity($emailId);
        if (null === $email) {
            return new Response('Email draft not found.', Response::HTTP_NOT_FOUND);
        }
        if (!$security->isAdmin() || !$security->hasEntityAccess(
            'email:emails:viewown',
            'email:emails:viewother',
            $email->getCreatedBy()
        )) {
            return new Response('Access denied.', Response::HTTP_FORBIDDEN);
        }

        try {
            $input = PreflightInput::fromEmail($email);
        } catch (PreflightException $exception) {
            return $this->page($emailId, (string) $email->getName(), '', $exception->getMessage(), null, false);
        }

        $session = $request->getSession();
        $intentKey = 'sendrepute.preflight.'.$emailId;
        $csrfId = 'sendrepute.preflight.'.$emailId;

        if (!$request->isMethod('POST')) {
            $nonce = bin2hex(random_bytes(24));
            $session->set($intentKey, [
                'nonceHash'   => hash('sha256', $nonce),
                'inputHash'   => hash('sha256', json_encode($input, JSON_UNESCAPED_SLASHES) ?: ''),
                'expiresAt'   => time() + 600,
            ]);

            return $this->page(
                $emailId,
                (string) $email->getName(),
                $nonce,
                null,
                null,
                self::paidEnabled(),
                $csrf->getToken($csrfId)->getValue()
            );
        }

        try {
            $lock = SpendLock::acquire($emailId);
        } catch (PreflightException $exception) {
            return $this->page($emailId, (string) $email->getName(), '', $exception->getMessage(), null, false);
        }
        try {
            $token = new CsrfToken($csrfId, (string) $request->request->get('_token', ''));
            $intent = $session->get($intentKey);
            $session->remove($intentKey); // Consumed while the atomic spend lock is held.
            $nonce = (string) $request->request->get('intent', '');
            $inputHash = hash('sha256', json_encode($input, JSON_UNESCAPED_SLASHES) ?: '');
            if (!$csrf->isTokenValid($token)
                || !is_array($intent)
                || ($intent['expiresAt'] ?? 0) < time()
                || !isset($intent['nonceHash'], $intent['inputHash'])
                || !hash_equals((string) $intent['nonceHash'], hash('sha256', $nonce))
                || !hash_equals((string) $intent['inputHash'], $inputHash)
            ) {
                return $this->page($emailId, (string) $email->getName(), '', 'The preflight confirmation expired or the draft changed. Review it again.', null, false);
            }
            if (!self::paidEnabled()) {
                return $this->page($emailId, (string) $email->getName(), '', 'Paid analysis is disabled by server configuration.', null, false);
            }
            if ('yes' !== $request->request->get('paid_confirmation')) {
                return $this->page($emailId, (string) $email->getName(), '', 'Explicit paid-analysis confirmation is required.', null, false);
            }

            try {
                // This durable ledger, not session removal, is authoritative.
                // It is flushed while the per-email lock is held and before HTTP.
                NonceLedger::consume($emailId, (string) $session->getId(), $nonce);
            } catch (PreflightException $exception) {
                return $this->page($emailId, (string) $email->getName(), '', $exception->getMessage(), null, false);
            }

            $apiKey = getenv('SENDREPUTE_API_KEY');
            try {
                $result = (new ApiClient())->classify($input, is_string($apiKey) ? $apiKey : '');
                return $this->page($emailId, (string) $email->getName(), '', null, $result, false);
            } catch (PreflightException $exception) {
                return $this->page($emailId, (string) $email->getName(), '', $exception->getMessage(), null, false);
            }
        } finally {
            $lock->release();
        }
    }

    /**
     * @param null|array{label:string,spamProbability:float,confidence:string,chargedMillicents:int,replayed:bool} $result
     */
    private function page(
        int $emailId,
        string $name,
        string $intent,
        ?string $error,
        ?array $result,
        bool $canSubmit,
        string $csrfToken = ''
    ): Response {
        $escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $body = '<main class="pa-lg"><h1>SendRepute draft preflight</h1>'
            .'<p><strong>Draft:</strong> '.$escape($name).' (#'.$emailId.')</p>'
            .'<p>This is an advisory analysis of the saved sender display name, subject, and body only. '
            .'It does not send or change the email, inspect recipients or attachments, block campaign sends, or guarantee inbox delivery.</p>';

        if (null !== $error) {
            $body .= '<div class="alert alert-danger"><strong>Analysis unavailable:</strong> '.$escape($error).'</div>';
        } elseif (null !== $result) {
            $body .= '<div class="alert alert-info"><strong>Advisory result:</strong> '
                .$escape($result['label']).'; spam probability '
                .number_format($result['spamProbability'] * 100, 1).'% ('
                .$escape($result['confidence']).' confidence). Charged '
                .number_format($result['chargedMillicents'] / 100000, 5)
                .' account currency units'.($result['replayed'] ? ' (replayed; no new debit)' : '').'.</div>';
        } elseif ($canSubmit) {
            $body .= '<div class="alert alert-warning"><strong>Paid operation:</strong> classification pricing is variable and account-specific. '
                .'The API reports the actual charge after completion. Check your SendRepute account pricing and spend limit before continuing.</div>'
                .'<form method="post">'
                .'<input type="hidden" name="_token" value="'.$escape($csrfToken).'">'
                .'<input type="hidden" name="intent" value="'.$escape($intent).'">'
                .'<label><input type="checkbox" name="paid_confirmation" value="yes" required> '
                .'I explicitly authorize one paid classification of this saved draft.</label><br><br>'
                .'<button class="btn btn-primary" type="submit">Run paid advisory analysis</button></form>';
        } else {
            $body .= '<div class="alert alert-warning">Paid analysis is disabled. A single-node deployment must set '
                .'<code>SENDREPUTE_MAUTIC_ATOMIC_LOCK_MODE=local-flock</code> and '
                .'<code>SENDREPUTE_MAUTIC_PAID_ANALYSIS_ENABLED=1</code>, plus a persistent private '
                .'<code>SENDREPUTE_MAUTIC_LEDGER_DIR</code>. Multi-node deployments are unsupported.</div>';
        }

        return new Response($body.'</main>', Response::HTTP_OK, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
            'Referrer-Policy' => 'same-origin',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'self'; form-action 'self'; frame-ancestors 'self'",
        ]);
    }

    private static function paidEnabled(): bool
    {
        $ledgerDirectory = getenv('SENDREPUTE_MAUTIC_LEDGER_DIR');

        return '1' === getenv('SENDREPUTE_MAUTIC_PAID_ANALYSIS_ENABLED')
            && 'local-flock' === getenv('SENDREPUTE_MAUTIC_ATOMIC_LOCK_MODE')
            && is_string($ledgerDirectory)
            && '' !== $ledgerDirectory;
    }
}