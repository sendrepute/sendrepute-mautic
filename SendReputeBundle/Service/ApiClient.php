<?php

declare(strict_types=1);

namespace MauticPlugin\SendReputeBundle\Service;

final class ApiClient
{
    public const API_URL = 'https://www.sendrepute.com/api/v1/classify';
    public const MAX_RESPONSE_BYTES = 1048576;

    /** @var callable(string,string):array{status:int,body:string} */
    private $transport;

    /**
     * @param null|callable(string,string):array{status:int,body:string} $transport
     */
    public function __construct(?callable $transport = null)
    {
        $this->transport = $transport ?? [self::class, 'curlTransport'];
    }

    /**
     * @param array{sender:string,subject:string,body:string} $input
     * @return array{label:string,spamProbability:float,confidence:string,chargedMillicents:int,replayed:bool}
     */
    public function classify(array $input, string $apiKey): array
    {
        if ('' === trim($apiKey) || strlen($apiKey) > 4096 || preg_match('/[\r\n]/', $apiKey)) {
            throw new PreflightException('auth', 'The server-side SendRepute credential is missing or invalid.');
        }

        $encoded = json_encode($input, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($encoded)) {
            throw new PreflightException('malformed', 'The draft could not be encoded for analysis.');
        }

        try {
            $response = ($this->transport)($encoded, $apiKey);
        } catch (PreflightException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            // Never chain transport exceptions: request headers and credentials
            // must remain absent from exception renderers and logs.
            throw new PreflightException('transport', 'SendRepute could not be reached securely.');
        }

        $status = $response['status'] ?? 0;
        $raw = $response['body'] ?? '';
        if (!is_int($status) || !is_string($raw) || strlen($raw) > self::MAX_RESPONSE_BYTES) {
            throw new PreflightException('malformed_response', 'SendRepute returned an invalid or oversized response.');
        }
        if ($status < 200 || $status >= 300) {
            throw self::statusException($status);
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)
            || JSON_ERROR_NONE !== json_last_error()
            || !isset($decoded['result'], $decoded['billing'])
            || !is_array($decoded['result'])
            || !is_array($decoded['billing'])
        ) {
            throw new PreflightException('malformed_response', 'SendRepute returned a malformed response.');
        }

        $result = $decoded['result'];
        $billing = $decoded['billing'];
        $probability = $result['spamProbability'] ?? null;
        $charge = $billing['chargedMillicents'] ?? null;
        $models = ['thor', 'theos', 'athena', 'odin', 'freya', 'hermes', 'ares', 'apollo'];
        $allowedResultKeys = [
            'label', 'spamProbability', 'flaggedTermCount', 'confidence', 'reasons',
            'flaggedTerms', 'analyzedFields', 'modelVersion', 'analyzedAt', 'contentAudit',
        ];
        if (!self::hasOnlyKeys($decoded, ['requestId', 'model', 'result', 'billing'])
            || !self::hasOnlyKeys($billing, ['chargedMillicents', 'replayed'])
            || !self::hasOnlyKeys($result, $allowedResultKeys)
            || !isset(
            $decoded['requestId'],
            $decoded['model'],
            $result['label'],
            $result['confidence'],
            $result['reasons'],
            $result['flaggedTerms'],
            $result['analyzedFields'],
            $result['modelVersion'],
            $result['analyzedAt'],
            $billing['replayed']
        )
            || !is_string($decoded['requestId'])
            || '' === $decoded['requestId']
            || strlen($decoded['requestId']) > 128
            || !in_array($decoded['model'], $models, true)
            || !in_array($result['label'], ['inbox', 'spam'], true)
            || (!is_int($probability) && !is_float($probability))
            || !is_finite((float) $probability)
            || (float) $probability < 0.0
            || (float) $probability > 1.0
            || !in_array($result['confidence'], ['low', 'medium', 'high'], true)
            || !self::validReasons($result['reasons'])
            || !self::stringList($result['flaggedTerms'])
            || !self::stringList($result['analyzedFields'])
            || !is_string($result['modelVersion'])
            || '' === $result['modelVersion']
            || !is_string($result['analyzedAt'])
            || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D', $result['analyzedAt'])
            || (isset($result['flaggedTermCount'])
                && (!is_int($result['flaggedTermCount']) || $result['flaggedTermCount'] < 0))
            || (isset($result['contentAudit']) && !self::validContentAudit($result['contentAudit']))
            || !is_int($charge)
            || $charge < 0
            || !is_bool($billing['replayed'])
        ) {
            throw new PreflightException('malformed_response', 'SendRepute returned an invalid classification result.');
        }

        return [
            'label'               => $result['label'],
            'spamProbability'     => (float) $probability,
            'confidence'          => $result['confidence'],
            'chargedMillicents'   => $charge,
            'replayed'            => $billing['replayed'],
        ];
    }

    /**
     * Reject undeclared response members while allowing optional declared ones.
     *
     * @param array<string,mixed> $value
     * @param list<string>        $allowed
     */
    private static function hasOnlyKeys(array $value, array $allowed): bool
    {
        return [] === array_diff(array_keys($value), $allowed);
    }

    /** @param mixed $value */
    private static function stringList($value): bool
    {
        if (!is_array($value) || !array_is_list($value)) {
            return false;
        }
        foreach ($value as $item) {
            if (!is_string($item)) {
                return false;
            }
        }

        return true;
    }

    /** @param mixed $value */
    private static function validReasons($value): bool
    {
        if (!is_array($value) || !array_is_list($value)) {
            return false;
        }
        foreach ($value as $reason) {
            $keys = is_array($reason) ? array_keys($reason) : [];
            sort($keys);
            if (!is_array($reason)
                || $keys !== ['detail', 'signal', 'weight']
                || !is_string($reason['signal'])
                || !is_string($reason['detail'])
                || (!is_int($reason['weight']) && !is_float($reason['weight']))
                || !is_finite((float) $reason['weight'])
            ) {
                return false;
            }
        }

        return true;
    }

    /** @param mixed $value */
    private static function validContentAudit($value): bool
    {
        if (!is_array($value)
            || !self::hasOnlyKeys($value, [
                'score', 'grade', 'summary', 'counts', 'totalIssues', 'criticalCount',
                'warningCount', 'suggestionCount', 'issues', 'goodPractices',
                'homoglyphTerms', 'inputTruncated',
            ])
            || !isset(
                $value['score'],
                $value['grade'],
                $value['summary'],
                $value['counts'],
                $value['totalIssues'],
                $value['criticalCount'],
                $value['warningCount'],
                $value['suggestionCount'],
                $value['issues'],
                $value['goodPractices'],
                $value['inputTruncated']
            )
            || !self::integerInRange($value['score'], 0, 100)
            || !in_array($value['grade'], ['A', 'B', 'C', 'D', 'F'], true)
            || !in_array($value['summary'], ['fix_critical', 'fix_warnings', 'review_suggestions', 'looks_good'], true)
            || !is_array($value['counts'])
            || !self::hasOnlyKeys($value['counts'], ['words', 'links', 'images', 'triggerPhrases'])
            || 4 !== count($value['counts'])
            || !self::allNonNegativeIntegers($value['counts'])
            || !self::integerInRange($value['totalIssues'], 0)
            || !self::integerInRange($value['criticalCount'], 0)
            || !self::integerInRange($value['warningCount'], 0)
            || !self::integerInRange($value['suggestionCount'], 0)
            || !is_bool($value['inputTruncated'])
            || (isset($value['homoglyphTerms']) && !self::stringList($value['homoglyphTerms']))
            || !self::validAuditIssues($value['issues'])
            || !self::validGoodPractices($value['goodPractices'])
        ) {
            return false;
        }

        return true;
    }

    /** @param mixed $value */
    private static function validAuditIssues($value): bool
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > 50) {
            return false;
        }
        foreach ($value as $issue) {
            if (!is_array($issue)
                || !self::hasOnlyKeys($issue, ['code', 'category', 'severity', 'deduction', 'evidence'])
                || count($issue) !== 5
                || !is_string($issue['code'] ?? null)
                || !in_array($issue['category'] ?? null, ['subject', 'content', 'links', 'structure', 'compliance'], true)
                || !in_array($issue['severity'] ?? null, ['critical', 'warning', 'suggestion'], true)
                || !self::integerInRange($issue['deduction'] ?? null, 0, 100)
                || !is_string($issue['evidence'] ?? null)
                || strlen($issue['evidence']) > 200
            ) {
                return false;
            }
        }

        return true;
    }

    /** @param mixed $value */
    private static function validGoodPractices($value): bool
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > 20) {
            return false;
        }
        foreach ($value as $practice) {
            if (!is_array($practice)
                || !self::hasOnlyKeys($practice, ['code', 'category'])
                || count($practice) !== 2
                || !is_string($practice['code'] ?? null)
                || !in_array($practice['category'] ?? null, ['subject', 'content', 'links', 'structure', 'compliance'], true)
            ) {
                return false;
            }
        }

        return true;
    }

    /** @param mixed $value */
    private static function integerInRange($value, int $minimum, ?int $maximum = null): bool
    {
        return is_int($value) && $value >= $minimum && (null === $maximum || $value <= $maximum);
    }

    /** @param array<string,mixed> $values */
    private static function allNonNegativeIntegers(array $values): bool
    {
        foreach ($values as $value) {
            if (!self::integerInRange($value, 0)) {
                return false;
            }
        }

        return true;
    }

    private static function statusException(int $status): PreflightException
    {
        return match ($status) {
            400, 413 => new PreflightException('malformed', 'SendRepute rejected the draft input. No spam decision was returned.'),
            401, 403 => new PreflightException('auth', 'SendRepute authentication or classification permission failed.'),
            402      => new PreflightException('balance', 'The SendRepute balance or spend limit is insufficient.'),
            429      => new PreflightException('rate_limit', 'The SendRepute rate limit was reached. No automatic retry was attempted.'),
            default  => new PreflightException('transport', 'SendRepute did not complete the analysis. No automatic retry was attempted.'),
        };
    }

    /**
     * @return array{status:int,body:string}
     */
    public static function curlTransport(string $body, string $apiKey): array
    {
        if (!function_exists('curl_init')) {
            throw new PreflightException('transport', 'The PHP cURL extension is required.');
        }

        $response = '';
        $tooLarge = false;
        $handle = curl_init(self::API_URL);
        if (false === $handle) {
            throw new PreflightException('transport', 'SendRepute could not be reached securely.');
        }

        curl_setopt_array($handle, [
            CURLOPT_POST            => true,
            CURLOPT_POSTFIELDS      => $body,
            CURLOPT_HTTPHEADER      => [
                'Accept: application/json',
                'Content-Type: application/json',
                'Authorization: Bearer '.$apiKey,
            ],
            CURLOPT_FOLLOWLOCATION  => false,
            CURLOPT_MAXREDIRS       => 0,
            CURLOPT_CONNECTTIMEOUT  => 5,
            CURLOPT_TIMEOUT         => 20,
            CURLOPT_SSL_VERIFYPEER  => true,
            CURLOPT_SSL_VERIFYHOST  => 2,
            CURLOPT_PROTOCOLS       => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_WRITEFUNCTION   => static function ($curl, string $chunk) use (&$response, &$tooLarge): int {
                if (strlen($response) + strlen($chunk) > self::MAX_RESPONSE_BYTES) {
                    $tooLarge = true;
                    return 0;
                }
                $response .= $chunk;
                return strlen($chunk);
            },
        ]);

        try {
            $ok = curl_exec($handle);
            $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        } finally {
            curl_close($handle);
        }

        if (false === $ok || $tooLarge) {
            throw new PreflightException(
                $tooLarge ? 'malformed_response' : 'transport',
                $tooLarge ? 'SendRepute returned an oversized response.' : 'SendRepute could not be reached securely.'
            );
        }
        if ($status >= 300 && $status < 400) {
            throw new PreflightException('transport', 'SendRepute redirects are refused to protect credentials.');
        }

        return ['status' => $status, 'body' => $response];
    }
}