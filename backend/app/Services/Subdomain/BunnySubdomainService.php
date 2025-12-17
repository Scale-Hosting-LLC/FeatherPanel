<?php

namespace App\Services\Subdomain;

use App\App;
use GuzzleHttp\Client;
use App\Config\ConfigInterface;
use GuzzleHttp\Exception\GuzzleException;

class BunnySubdomainService
{
    private ?Client $httpClient = null;
    private bool $available = false;
    private ?string $apiKey = null;

    public function __construct(?string $apiKey)
    {
        $apiKey = $apiKey !== null ? trim($apiKey) : '';
        if ($apiKey === '') {
            return;
        }

        $this->apiKey = $apiKey;

        $this->httpClient = new Client([
            'base_uri' => 'https://api.bunny.net/',
            'headers' => [
                'Content-Type' => 'application/json',
                'AccessKey' => $this->apiKey,
            ],
            'timeout' => 15,
        ]);

        $this->available = true;
    }

    public static function fromConfig(): self
    {
        $app = App::getInstance(true);
        $config = $app->getConfig();

        return new self($config->getSetting(ConfigInterface::SUBDOMAIN_BUNNY_API_KEY, ''));
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }

    /**
     * For Bunny DNS, treat the provided domain as the zone identifier.
     */
    public function resolveZoneId(string $domain): ?string
    {
        if (!$this->available || $this->httpClient === null) {
            return null;
        }

        try {
            $resp = $this->httpClient->get('dnszone');
            $zones = json_decode((string) $resp->getBody(), true);

            if (is_array($zones) && !empty($zones['Items'])) {
                foreach ($zones['Items'] as $zone) {
                    if (isset($zone['Domain']) && $zone['Domain'] === $domain) {
                        return (string) $zone['Id'];
                    }
                }
            }
        } catch (GuzzleException $ex) {
            App::getInstance(true)->getLogger()->error('Bunny DNS zone lookup failed: ' . $ex->getMessage());
        }

        return null;
    }

    public function ensureRecordDoesNotExist(string $zoneId, string $type, string $name): bool
    {
        if (!$this->available || $this->httpClient === null) {
            return false;
        }

        try {
            $resp = $this->httpClient->get(sprintf('dnszone/%s/records', $zoneId), [
                'query' => ['type' => $type, 'name' => $name],
            ]);
            $body = json_decode((string) $resp->getBody(), true);
            if (is_array($body) && !empty($body)) {
                return count($body) === 0;
            }
        } catch (GuzzleException $ex) {
            App::getInstance(true)->getLogger()->error('Bunny DNS list records failed: ' . $ex->getMessage());
        }

        return false;
    }

    public function createCnameRecord(string $zoneId, string $fqdn, string $target, int $ttl = 120): ?string
    {
        return $this->createRecord($zoneId, [
            'type' => 'CNAME',
            'name' => $fqdn,
            'content' => $target,
            'ttl' => $ttl,
        ]);
    }

    public function createAddressRecord(string $zoneId, string $fqdn, string $ip, string $type = 'A', int $ttl = 120): ?string
    {
        return $this->createRecord($zoneId, [
            'type' => $type,
            'name' => $fqdn,
            'content' => $ip,
            'ttl' => $ttl,
        ]);
    }

    public function createSrvRecord(
        string $zoneId,
        string $service,
        string $protocol,
        string $subdomainLabel,
        string $domain,
        string $target,
        int $port,
        int $priority = 1,
        int $weight = 1,
        int $ttl = 120,
    ): ?string {
        $name = $service . '._' . $protocol . '.' . $subdomainLabel . '.' . $domain;
        $data = [
            'type' => 'SRV',
            'name' => $name,
            'ttl' => $ttl,
            'data' => [
                'service' => $service,
                'proto' => '_' . $protocol,
                'name' => $subdomainLabel . '.' . $domain,
                'priority' => $priority,
                'weight' => $weight,
                'port' => $port,
                'target' => $target,
            ],
        ];

        return $this->createRecord($zoneId, $data);
    }

    public function deleteRecord(string $zoneId, string $recordId): bool
    {
        if (!$this->available || $this->httpClient === null) {
            return false;
        }

        try {
            $this->httpClient->delete(sprintf('dnszone/%s/records/%s', $zoneId, $recordId));
            return true;
        } catch (GuzzleException $ex) {
            App::getInstance(true)->getLogger()->error('Bunny DNS record deletion failed: ' . $ex->getMessage());
        }

        return false;
    }

    public function deleteRecordByName(string $zoneId, string $type, string $name): bool
    {
        if (!$this->available || $this->httpClient === null) {
            return false;
        }

        try {
            $resp = $this->httpClient->get(sprintf('dnszone/%s/records', $zoneId), [
                'query' => ['type' => $type, 'name' => $name],
            ]);
            $body = json_decode((string) $resp->getBody(), true);
            if (is_array($body) && !empty($body)) {
                $record = $body[0];
                if (!empty($record['id'])) {
                    return $this->deleteRecord($zoneId, $record['id']);
                }
            }
        } catch (GuzzleException $ex) {
            App::getInstance(true)->getLogger()->error('Bunny DNS delete by name failed: ' . $ex->getMessage());
        }

        return false;
    }

    private function createRecord(string $zoneId, array $payload): ?string
    {
        if ($this->httpClient === null) {
            return null;
        }

        try {
            $resp = $this->httpClient->post(sprintf('dnszone/%s/records', $zoneId), ['json' => $payload]);
            $body = json_decode((string) $resp->getBody(), true);
            if (is_array($body) && !empty($body['id'])) {
                return (string) $body['id'];
            }
        } catch (GuzzleException $ex) {
            App::getInstance(true)->getLogger()->error('Bunny DNS record creation failed: ' . $ex->getMessage());
        }

        return null;
    }
}
