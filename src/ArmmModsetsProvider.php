<?php
namespace ArmmModsets;

use ArmmModsets\Armm\ArmmSettings;
use Pterodactyl\Extensions\ExtensionProvider;
use Pterodactyl\Services\Extensions\ExtensionSettingDefinition;
use Pterodactyl\Services\Extensions\ExtensionSettingsDefinition;
use Pterodactyl\Services\Extensions\ExtensionSettingsRegistry;

final class ArmmModsetsProvider extends ExtensionProvider
{
    public function register(): void
    {
        // Resolved per use, so changed settings apply immediately and invalid ones surface as ArmmException.
        $this->app->bind(ArmmSettings::class, fn ($app): ArmmSettings => ArmmSettings::fromRegistry($app->make(ExtensionSettingsRegistry::class)));
    }

    public function boot(): void
    {
        $this->registerApiRoutes();
        $this->loadExtensionMigrations();
        $this->registerSettings(new ExtensionSettingsDefinition($this->settings(), [
            ExtensionSettingDefinition::make('url', 'url', '', ['required', 'url', 'starts_with:https://', 'max:512'])
                ->label('ARMM URL')->help('HTTPS base URL of ARMM, without /api.'),
            ExtensionSettingDefinition::make('username', 'username', '', ['required', 'string', 'max:255'])
                ->label('ARMM API user'),
            ExtensionSettingDefinition::make('password', 'password', '', ['nullable', 'string', 'max:2048'])
                ->label('ARMM API password')->secret(),
            ExtensionSettingDefinition::make('tls_verify', 'tls_verify', '1', ['required', 'in:0,1'])
                ->label('Verify ARMM HTTPS certificates')->field('select', [
                    ['value' => '1', 'label' => 'Yes (recommended)'],
                    ['value' => '0', 'label' => 'No (insecure)'],
                ]),
            ExtensionSettingDefinition::make('keep_versions', 'keep_versions', '1', ['required', 'in:0,1'])
                ->label('Keep versions returned by ARMM')->field('select', [
                    ['value' => '1', 'label' => 'Yes'],
                    ['value' => '0', 'label' => 'No'],
                ]),
        ]));
    }
}
