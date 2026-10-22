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
use Pterodactyl\Models\Extension;
use Pterodactyl\Models\Server;
use Pterodactyl\Services\Extensions\ExtensionProviderLoader;
use Pterodactyl\Services\Extensions\ExtensionRepository;
use Pterodactyl\Tests\Integration\IntegrationTestCase;

uses(IntegrationTestCase::class, DatabaseTransactions::class);

beforeEach(function (): void {
    config([
        'extensions.enabled' => true,
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
        ['version' => '1.1.2', 'enabled' => true],
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

test('syncing replaces only game mods and creates a backup', function (): void {
    [$user, $server] = reforgerServer();
    DB::table('ext_armm_modsets_assignments')->insert([
        'server_id' => $server->id,
        'modset_id' => 12,
        'modset_name' => 'Primary Modset',
        'updated_at' => now(),
    ]);
    $original = "{\n  \"game\": {\"mods\": []},\n  \"name\": \"Test server\"\n}\n";
    $reader = new class($original) implements ReadsFileContents {
        public function __construct(private readonly string $contents) {}
        public function read(Server $server, string $path, ?int $maxBytes = null): string { return $this->contents; }
    };
    $writer = new class implements WritesFileContents {
        /** @var array<string, string> */
        public array $files = [];
        public function write(Server $server, string $path, string $contents): void { $this->files[$path] = $contents; }
    };
    $this->app->instance(ReadsFileContents::class, $reader);
    $this->app->instance(WritesFileContents::class, $writer);
    Http::fake([
        'https://armm.example.test/api/auth/login' => Http::response([], 200),
        'https://armm.example.test/api/modsets/12/export' => Http::response([
            ['modId' => '0123456789ABCDEF', 'name' => 'Test mod', 'version' => '1.0.0'],
        ], 200),
    ]);

    $this->actingAs($user)
        ->postJson("/api/client/servers/{$server->uuid}/extensions/armm-modsets/sync")
        ->assertOk()
        ->assertJsonPath('count', 1);

    expect($writer->files['config.json.armm.bak'])->toBe($original);
    expect(json_decode($writer->files['config.json'], true, 512, JSON_THROW_ON_ERROR))
        ->toBe([
            'game' => ['mods' => [['modId' => '0123456789ABCDEF', 'name' => 'Test mod', 'version' => '1.0.0']]],
            'name' => 'Test server',
        ]);
});
