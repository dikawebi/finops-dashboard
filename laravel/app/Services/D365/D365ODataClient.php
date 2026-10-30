<?php

namespace App\Services\D365;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class D365ODataClient
{
    private const TOKEN_CACHE_KEY = 'd365_access_token';

    public function __construct(private readonly array $config) {}

    public function enabled(): bool
    {
        return (bool) ($this->config['enabled'] ?? false)
            && collect(['environment_url', 'tenant_id', 'client_id', 'client_secret'])->every(fn (string $key): bool => filled($this->config[$key] ?? null));
    }

    public function environmentUrl(): string
    {
        return rtrim((string) ($this->config['environment_url'] ?? ''), '/');
    }

    public function streamEntity(string $entityName, ?array $select = null, ?string $filter = null, ?string $orderBy = null, int $top = 1000, int $maxPages = 2000): \Generator
    {
        $offset = 0;
        $page = 0;
        $base = $this->environmentUrl().'/data/'.rawurlencode($entityName);
        while ($page < $maxPages) {
            $query = ['cross-company' => 'true', '$top' => $top, '$skip' => $offset];
            if ($select) $query['$select'] = implode(',', $select);
            if (filled($filter)) $query['$filter'] = $filter;
            if (filled($orderBy)) $query['$orderby'] = $orderBy;
            $response = Http::acceptJson()->timeout(120)->withToken($this->accessToken())->get($base, $query);
            if ($response->status() === 401) {
                Cache::forget(self::TOKEN_CACHE_KEY);
                throw new D365Exception("Token ditolak oleh D365 untuk entity {$entityName}.");
            }
            if ($response->failed()) throw new D365Exception("GET {$entityName} gagal: HTTP {$response->status()} — ".Str::limit($response->body(), 300));
            $rows = $response->json('value', []);
            foreach ($rows as $row) yield $row;
            if (count($rows) < $top) return;
            $offset += $top;
            $page++;
        }
    }

    public function accessToken(): string
    {
        if ($token = Cache::get(self::TOKEN_CACHE_KEY)) return (string) $token;
        $url = 'https://login.microsoftonline.com/'.rawurlencode((string) $this->config['tenant_id']).'/oauth2/v2.0/token';
        $response = Http::asForm()->timeout(30)->post($url, [
            'grant_type' => 'client_credentials',
            'client_id' => $this->config['client_id'],
            'client_secret' => $this->config['client_secret'],
            'scope' => $this->environmentUrl().'/.default',
        ]);
        if ($response->failed()) {
            Log::error('D365 token request failed', ['status' => $response->status()]);
            throw new D365Exception('Autentikasi D365 gagal: HTTP '.$response->status());
        }
        $token = (string) $response->json('access_token', '');
        if ($token === '') throw new D365Exception('Response D365 tidak berisi access_token.');
        Cache::put(self::TOKEN_CACHE_KEY, $token, max(60, (int) ($this->config['token_cache_seconds'] ?? 3000)));
        return $token;
    }
}
