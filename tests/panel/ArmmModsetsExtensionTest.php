<?php

declare(strict_types=1);

namespace Pterodactyl\Tests\Pest\Integration\Extensions\ArmmModsets;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Pterodactyl\Contracts\Files\ReadsFileContents;
use Pterodactyl\Contracts\Files\WritesFileContents;
use Pterodactyl\Contracts\Servers\ReadsServerState;
use Pterodactyl\Data\ServerState;
use Pterodactyl\Models\Extension;
use Pterodactyl\Models\Server;
use Pterodactyl\Services\Extensions\ExtensionProviderLoader;
use Pterodactyl\Services\Extensions\ExtensionRepository;
use Pterodactyl\Tests\Integration\IntegrationTestCase;

uses(IntegrationTestCase::class, DatabaseTransactions::class);

function extensionVersion(): string
{
    $manifest = json_decode(file_get_contents(base_path('tests/Fixtures/extensions/armm-modsets/extension.json')), true, 512, JSON_THROW_ON_ERROR);
    return $manifest['version'];
}

beforeEach(function (): void {
    config([
        'extensions.enabled' => true,
        'extensions.panel_version' => '2.0.0-beta.2',
        'extensions.directory' => base_path('tests/Fixtures/extensions'),
        'extensions.assets_directory' => base_path('tests/Fixtures/extensions-assets'),
    ]);
    Cache::flush();

    if (! Schema::hasTable('ext_armm_modsets_assignments')) {
        Schema::create('ext_armm_modsets_assignments', function ($table): void {
            $table->id();
            $table->unsignedInteger('server_id')->unique();
            $table->unsignedInteger('modset_id');
            $table->string('modset_name', 255)->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->unsignedInteger('last_count')->nullable();
        });
    }

    Extension::query()->updateOrCreate(
        ['identifier' => 'armm-modsets'],
        ['version' => extensionVersion(), 'enabled' => true],
    );

    $this->app->forgetInstance(ExtensionRepository::class);
    $this->app->forgetInstance(ExtensionProviderLoader::class);
    $repository = $this->app->make(ExtensionRepository::class);
    $repository->flushDiscovery();
    $enabled = $repository->enabled();
    if (! $enabled->has('armm-modsets')) {
        throw new \RuntimeException('ARMM extension is not enabled: '.json_encode([
            'discovery_errors' => $repository->discoveryErrors(),
            'extension_error' => Extension::query()->where('identifier', 'armm-modsets')->value('error'),
        ], JSON_THROW_ON_ERROR));
    }
    $this->app->make(ExtensionProviderLoader::class)->registerProviders($enabled);
    $failure = Extension::query()->where('identifier', 'armm-modsets')->value('error');
    if ($failure !== null) throw new \RuntimeException('ARMM extension failed to boot: '.$failure);
    $repository->settings('armm-modsets')->setMany([
        'url' => 'https://armm.example.test',
        'username' => 'test-user',
        'password' => 'test-password',
        'tls_verify' => '1',
        'keep_versions' => '1',
    ]);
});

afterEach(function (): void {
    $this->app->make(ExtensionRepository::class)->flushDiscovery();
});

function armmResponses(array $modsets): void
{
    Http::fake([
        'https://armm.example.test/api/auth/login' => Http::response([], 200),
        'https://armm.example.test/api/modsets' => Http::response($modsets, 200),
    ]);
}

function reforgerServer(): array
{
    [$user, $server] = pterodactylTestCase()->generateTestAccount();
    $server->update(['startup' => './ArmaReforgerServer']);

    return [$user, $server->fresh()];
}

function modsetsUrl(Server $server): string
{
    return "/api/client/servers/{$server->uuid}/extensions/armm-modsets/modsets";
}

test('server owners can load modsets and the result is cached', function (): void {
    [$user, $server] = reforgerServer();
    armmResponses([
        ['id' => 12, 'name' => 'Primary Modset'],
        ['id' => '13', 'title' => 'Secondary Modset'],
    ]);

    $this->actingAs($user)->getJson(modsetsUrl($server))
        ->assertOk()
        ->assertJsonPath('modsets.0', ['id' => 12, 'name' => 'Primary Modset'])
        ->assertJsonPath('modsets.1', ['id' => 13, 'name' => 'Secondary Modset']);
    $this->actingAs($user)->getJson(modsetsUrl($server))->assertOk();

    Http::assertSentCount(2);
});

test('a refresh bypasses the cached modset list', function (): void {
    [$user, $server] = reforgerServer();
    armmResponses([['id' => 12, 'name' => 'Primary Modset']]);

    $this->actingAs($user)->getJson(modsetsUrl($server))->assertOk();
    $this->actingAs($user)->postJson(modsetsUrl($server).'/refresh')->assertOk();

    Http::assertSentCount(4);
});

test('subusers need file update permission to manage modsets', function (): void {
    [$user, $server] = $this->generateTestAccount(['file.update']);
    $server->update(['startup' => './ArmaReforgerServer']);
    armmResponses([['id' => 12, 'name' => 'Primary Modset']]);

    $this->actingAs($user)->getJson(modsetsUrl($server))->assertOk();

    [$deniedUser, $deniedServer] = $this->generateTestAccount(['file.read']);
    $deniedServer->update(['startup' => './ArmaReforgerServer']);
    $this->actingAs($deniedUser)->getJson(modsetsUrl($deniedServer))->assertForbidden();
});

test('saving an available modset persists its last known name', function (): void {
    [$user, $server] = reforgerServer();
    armmResponses([['id' => 12, 'name' => 'Primary Modset']]);

    $this->actingAs($user)
        ->putJson("/api/client/servers/{$server->uuid}/extensions/armm-modsets/assignment", ['modset_id' => 12])
        ->assertOk()
        ->assertJsonPath('modset_id', 12);

    $this->assertDatabaseHas('ext_armm_modsets_assignments', [
        'server_id' => $server->id,
        'modset_id' => 12,
        'modset_name' => 'Primary Modset',
    ]);
});

test('saving a modset that is not available is rejected', function (): void {
    [$user, $server] = reforgerServer();
    armmResponses([['id' => 12, 'name' => 'Primary Modset']]);

    $this->actingAs($user)
        ->putJson("/api/client/servers/{$server->uuid}/extensions/armm-modsets/assignment", ['modset_id' => 99])
        ->assertUnprocessable()
        ->assertJsonPath('error', 'The selected modset is not available. Refresh the modset list and try again.');
});

/**
 * Bind fake Wings file and power-state access for sync tests and return the writer,
 * whose $files collects everything written.
 */
function fakeWings(string $config, string $state = 'offline'): object
{
    $app = app();
    $app->instance(ReadsServerState::class, new class($state) implements ReadsServerState {
        public function __construct(private readonly string $state) {}
        public function read(Server $server): ServerState { return new ServerState($this->state, false, 0, 0, 0, 0, 0, 0); }
    });
    $app->instance(ReadsFileContents::class, new class($config) implements ReadsFileContents {
        public function __construct(private readonly string $contents) {}
        public function read(Server $server, string $path, ?int $maxBytes = null): string { return $this->contents; }
    });
    $writer = new class implements WritesFileContents {
        /** @var array<string, string> */
        public array $files = [];
        public function write(Server $server, string $path, string $contents): void { $this->files[$path] = $contents; }
    };
    $app->instance(WritesFileContents::class, $writer);

    return $writer;
}

function assignedReforgerServer(): array
{
    [$user, $server] = reforgerServer();
    DB::table('ext_armm_modsets_assignments')->insert([
        'server_id' => $server->id,
        'modset_id' => 12,
        'modset_name' => 'Primary Modset',
        'updated_at' => now(),
    ]);

    return [$user, $server];
}

function armmExport(array $mods): void
{
    Http::fake([
        'https://armm.example.test/api/auth/login' => Http::response([], 200),
        'https://armm.example.test/api/modsets/12/export' => Http::response($mods, 200),
    ]);
}

function syncUrl(Server $server): string
{
    return "/api/client/servers/{$server->uuid}/extensions/armm-modsets/sync";
}

test('syncing replaces only game mods and creates a backup', function (): void {
    [$user, $server] = assignedReforgerServer();
    $original = "{\n  \"game\": {\"mods\": []},\n  \"name\": \"Test server\"\n}\n";
    $writer = fakeWings($original);
    armmExport([['modId' => '0123456789ABCDEF', 'name' => 'Test mod', 'version' => '1.0.0']]);

    $this->actingAs($user)->postJson(syncUrl($server))
        ->assertOk()
        ->assertJsonPath('count', 1);

    $backups = array_values(array_filter(array_keys($writer->files), fn (string $path): bool => preg_match('/^config\.json\.armm-\d{8}-\d{6}\.bak$/', $path) === 1));
    expect($backups)->toHaveCount(1);
    expect($writer->files[$backups[0]])->toBe($original);
    expect(json_decode($writer->files['config.json'], true, 512, JSON_THROW_ON_ERROR))
        ->toBe([
            'game' => ['mods' => [['modId' => '0123456789ABCDEF', 'name' => 'Test mod', 'version' => '1.0.0']]],
            'name' => 'Test server',
        ]);
    $this->assertDatabaseHas('ext_armm_modsets_assignments', ['server_id' => $server->id, 'last_count' => 1]);
});

test('syncing is rejected while the server is running', function (): void {
    [$user, $server] = assignedReforgerServer();
    $writer = fakeWings('{"game":{"mods":[]}}', 'running');
    armmExport([['modId' => '0123456789ABCDEF']]);

    $this->actingAs($user)->postJson(syncUrl($server))
        ->assertStatus(409)
        ->assertJsonPath('error', 'Stop the server before synchronizing config.json.');

    expect($writer->files)->toBe([]);
});

test('syncing rejects an export with an invalid mod id', function (): void {
    [$user, $server] = assignedReforgerServer();
    $writer = fakeWings('{"game":{"mods":[]}}');
    armmExport([['modId' => '../../etc/passwd']]);

    $this->actingAs($user)->postJson(syncUrl($server))
        ->assertStatus(502)
        ->assertJsonPath('error', 'ARMM export contains an invalid modId.');

    expect($writer->files)->toBe([]);
});

test('syncing rejects a config without a game object', function (): void {
    [$user, $server] = assignedReforgerServer();
    $writer = fakeWings('{"name":"Test server"}');
    armmExport([['modId' => '0123456789ABCDEF']]);

    $this->actingAs($user)->postJson(syncUrl($server))
        ->assertUnprocessable()
        ->assertJsonPath('error', 'config.json must contain a game object.');

    expect($writer->files)->toBe([]);
});

test('unexpected errors are not exposed to the client', function (): void {
    [$user, $server] = assignedReforgerServer();
    fakeWings('{"game":{"mods":[]}}');
    $this->app->instance(WritesFileContents::class, new class implements WritesFileContents {
        public function write(Server $server, string $path, string $contents): void { throw new \RuntimeException('secret internal detail /srv/daemon'); }
    });
    armmExport([['modId' => '0123456789ABCDEF']]);

    $response = $this->actingAs($user)->postJson(syncUrl($server))->assertStatus(500);

    expect($response->json('error'))->toBe('Unexpected error. Details were written to the panel log.');
});

test('armm authentication failures are reported to the user', function (): void {
    [$user, $server] = reforgerServer();
    Http::fake(['https://armm.example.test/api/auth/login' => Http::response([], 401)]);

    $this->actingAs($user)->getJson(modsetsUrl($server))
        ->assertStatus(502)
        ->assertJsonPath('error', 'ARMM authentication failed (HTTP 401).');
});

test('modset actions are rejected for servers that are not Arma Reforger', function (): void {
    [$user, $server] = $this->generateTestAccount();
    $server->update(['startup' => './minecraft']);

    $this->actingAs($user)->getJson(modsetsUrl($server))->assertUnprocessable();
});
