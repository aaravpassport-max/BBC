<?php

namespace RTOFLOW\Storage;

use RTOFLOW\Config\Env;

if (!defined('ABSPATH')) exit;

/**
 * ENTERPRISE GAP FIX (Phase 2, item 7 — file storage abstraction): a real
 * S3-compatible driver (AWS S3 and any S3-compatible service — MinIO,
 * DigitalOcean Spaces, Cloudflare R2 — that accepts AWS Signature Version 4)
 * implemented with a pure-PHP SigV4 signer over wp_remote_request(), the
 * same HTTP layer already used for Razorpay elsewhere in this codebase, so
 * there is no new HTTP client dependency and no assumption that the AWS
 * SDK is installed via composer.
 *
 * Configuration (env vars, matching this codebase's existing convention for
 * infra-level secrets — see Env::required() usage elsewhere):
 *   RTOFLOW_STORAGE_DRIVER=s3
 *   RTOFLOW_S3_BUCKET, RTOFLOW_S3_REGION, RTOFLOW_S3_ACCESS_KEY,
 *   RTOFLOW_S3_SECRET_KEY, RTOFLOW_S3_ENDPOINT (optional — omit for real
 *   AWS S3, set for an S3-compatible provider)
 */
class S3StorageDriver implements StorageDriver
{
    private string $bucket;
    private string $region;
    private string $accessKey;
    private string $secretKey;
    private string $endpoint;

    public function __construct()
    {
        $this->bucket    = Env::string('RTOFLOW_S3_BUCKET', '');
        $this->region    = Env::string('RTOFLOW_S3_REGION', 'ap-south-1');
        $this->accessKey = Env::string('RTOFLOW_S3_ACCESS_KEY', '');
        $this->secretKey = Env::string('RTOFLOW_S3_SECRET_KEY', '');
        $this->endpoint  = Env::string('RTOFLOW_S3_ENDPOINT', "https://s3.{$this->region}.amazonaws.com");
    }

    private function configured(): bool
    {
        return $this->bucket !== '' && $this->accessKey !== '' && $this->secretKey !== '';
    }

    public function put(string $relativePath, string $contents): bool
    {
        if (!$this->configured()) {
            error_log('RTOFLOW S3StorageDriver: not configured (missing bucket/access key/secret key) — refusing to write.');
            return false;
        }
        $resp = $this->request('PUT', $relativePath, $contents);
        return !is_wp_error($resp) && wp_remote_retrieve_response_code($resp) < 300;
    }

    public function putFromUploadedFile(string $relativePath, string $tmpName): bool
    {
        $contents = file_get_contents($tmpName);
        if ($contents === false) return false;
        return $this->put($relativePath, $contents);
    }

    public function get(string $relativePath): ?string
    {
        if (!$this->configured()) return null;
        $resp = $this->request('GET', $relativePath, '');
        if (is_wp_error($resp) || wp_remote_retrieve_response_code($resp) >= 300) return null;
        return wp_remote_retrieve_body($resp);
    }

    public function delete(string $relativePath): bool
    {
        if (!$this->configured()) return false;
        $resp = $this->request('DELETE', $relativePath, '');
        return !is_wp_error($resp) && wp_remote_retrieve_response_code($resp) < 300;
    }

    public function exists(string $relativePath): bool
    {
        if (!$this->configured()) return false;
        $resp = $this->request('HEAD', $relativePath, '');
        return !is_wp_error($resp) && wp_remote_retrieve_response_code($resp) < 300;
    }

    private function request(string $method, string $relativePath, string $body)
    {
        $key = '/' . ltrim($relativePath, '/');
        $url = rtrim($this->endpoint, '/') . '/' . $this->bucket . $key;

        $headers = $this->signedHeaders($method, $key, $body);

        return wp_remote_request($url, [
            'method'  => $method,
            'headers' => $headers,
            'body'    => $method === 'GET' || $method === 'HEAD' || $method === 'DELETE' ? null : $body,
            'timeout' => 30,
        ]);
    }

    /**
     * AWS Signature Version 4, single-shot (non-streaming) signing —
     * sufficient for the document sizes this platform handles (10MB cap,
     * see DocumentService::upload()). Follows the canonical AWS SigV4
     * recipe: canonical request → string to sign → signing key → signature.
     */
    private function signedHeaders(string $method, string $canonicalUri, string $body): array
    {
        $now       = new \DateTime('now', new \DateTimeZone('UTC'));
        $amzDate   = $now->format('Ymd\THis\Z');
        $dateStamp = $now->format('Ymd');
        $host      = parse_url($this->endpoint, PHP_URL_HOST) ?: "s3.{$this->region}.amazonaws.com";
        $payloadHash = hash('sha256', $body);

        $canonicalHeaders = "host:{$host}\nx-amz-content-sha256:{$payloadHash}\nx-amz-date:{$amzDate}\n";
        $signedHeadersStr = 'host;x-amz-content-sha256;x-amz-date';

        $canonicalRequest = implode("\n", [
            $method,
            $canonicalUri,
            '', // no query string
            $canonicalHeaders,
            $signedHeadersStr,
            $payloadHash,
        ]);

        $credentialScope = "{$dateStamp}/{$this->region}/s3/aws4_request";
        $stringToSign = implode("\n", [
            'AWS4-HMAC-SHA256',
            $amzDate,
            $credentialScope,
            hash('sha256', $canonicalRequest),
        ]);

        $signingKey = $this->signingKey($dateStamp);
        $signature  = hash_hmac('sha256', $stringToSign, $signingKey);

        $authHeader = "AWS4-HMAC-SHA256 Credential={$this->accessKey}/{$credentialScope}, "
            . "SignedHeaders={$signedHeadersStr}, Signature={$signature}";

        return [
            'Host'                 => $host,
            'X-Amz-Date'           => $amzDate,
            'X-Amz-Content-Sha256' => $payloadHash,
            'Authorization'        => $authHeader,
        ];
    }

    private function signingKey(string $dateStamp): string
    {
        $kDate    = hash_hmac('sha256', $dateStamp, 'AWS4' . $this->secretKey, true);
        $kRegion  = hash_hmac('sha256', $this->region, $kDate, true);
        $kService = hash_hmac('sha256', 's3', $kRegion, true);
        return hash_hmac('sha256', 'aws4_request', $kService, true);
    }
}
