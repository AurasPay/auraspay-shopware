<?php declare(strict_types=1);

namespace AurasPay\Shopware\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

final class AurasPayClient
{
    public function __construct(private readonly HttpClientInterface $httpClient)
    {
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public function createPayment(string $apiKey, array $payload): array
    {
        return $this->request('POST', '/api/v1/payment-links', $apiKey, $payload);
    }

    /** @return array<string, mixed> */
    public function getPayment(string $apiKey, string $paymentId): array
    {
        return $this->request('GET', '/api/v1/payment-links/' . rawurlencode($paymentId), $apiKey);
    }

    /** @param array<string, mixed>|null $payload @return array<string, mixed> */
    private function request(string $method, string $path, string $apiKey, ?array $payload = null): array
    {
        $options = [
            'headers' => ['Authorization' => 'Bearer ' . trim($apiKey), 'Accept' => 'application/json'],
            'timeout' => 25,
            'max_duration' => 25,
        ];
        if ($payload !== null) {
            $options['json'] = $payload;
        }

        $response = $this->httpClient->request($method, 'https://auraspay.com' . $path, $options);
        $status = $response->getStatusCode();
        $body = $response->toArray(false);
        if ($status < 200 || $status >= 300 || ($body['success'] ?? true) === false) {
            throw new \RuntimeException((string) ($body['message'] ?? $body['error'] ?? ('AURAS Pay returned HTTP ' . $status)));
        }

        $data = $body['data'] ?? $body;
        if (!is_array($data)) {
            throw new \RuntimeException('AURAS Pay returned an invalid response.');
        }
        return $data;
    }
}
