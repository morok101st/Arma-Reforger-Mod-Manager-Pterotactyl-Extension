<?php
namespace ArmmModsets\Armm;

use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

final readonly class ArmmClient
{
    public function __construct(private ArmmSettings $settings) {}

    /** Log in with the configured ARMM account and return the decoded JSON of a GET request. */
    public function get(string $endpoint): array
    {
        // The session cookie lives only in memory for the duration of this call.
        $jar = new CookieJar();
        try {
            $login = $this->request($jar, 15)->post($this->settings->url.'/api/auth/login', [
                'username' => $this->settings->username,
                'password' => $this->settings->password,
            ]);
            if (!$login->successful()) {
                throw new ArmmException('ARMM authentication failed (HTTP '.$login->status().').');
            }
            $response = $this->request($jar, 20)->get($this->settings->url.$endpoint);
        } catch (ConnectionException) {
            throw new ArmmException('ARMM could not be reached.');
        }
        if (!$response->successful()) {
            throw new ArmmException('ARMM request failed (HTTP '.$response->status().'). Check modset permissions.');
        }
        $data = $response->json();
        if (!is_array($data)) {
            throw new ArmmException('ARMM returned invalid JSON.');
        }

        return $data;
    }

    private function request(CookieJar $jar, int $timeout): PendingRequest
    {
        return Http::withOptions(['verify' => $this->settings->verifyTls, 'cookies' => $jar, 'allow_redirects' => false])
            ->timeout($timeout)
            ->acceptJson();
    }
}
