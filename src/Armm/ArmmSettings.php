<?php
namespace ArmmModsets\Armm;

use Pterodactyl\Services\Extensions\ExtensionSettingsRegistry;

final readonly class ArmmSettings
{
    public function __construct(
        public string $url,
        public string $username,
        public string $password,
        public bool $verifyTls,
        public bool $keepVersions,
    ) {}

    public static function fromRegistry(ExtensionSettingsRegistry $registry): self
    {
        $settings = $registry->get('armm-modsets');
        $url = rtrim((string) $settings->get('url'), '/');
        // Restrict outbound destinations to the configured HTTPS host, never a user-supplied URL.
        if (!str_starts_with($url, 'https://') || !filter_var($url, FILTER_VALIDATE_URL)) {
            throw new ArmmException('Configure an HTTPS ARMM URL in Admin > Extensions.');
        }
        $username = (string) $settings->get('username');
        $password = (string) $settings->get('password');
        if ($username === '' || $password === '') {
            throw new ArmmException('ARMM username or password not configured.');
        }

        return new self(
            $url,
            $username,
            $password,
            (string) $settings->get('tls_verify') !== '0',
            (string) $settings->get('keep_versions') !== '0',
        );
    }

    /** Identifies the ARMM account without exposing its credentials, e.g. for cache keys. */
    public function fingerprint(): string
    {
        return hash('sha256', $this->url."\0".$this->username."\0".$this->password);
    }
}
