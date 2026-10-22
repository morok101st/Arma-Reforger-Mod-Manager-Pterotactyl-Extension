<?php
namespace ArmmModsets\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Http;
use Pterodactyl\Models\Server;
use Pterodactyl\Contracts\Files\ReadsFileContents;
use Pterodactyl\Contracts\Files\WritesFileContents;
use Pterodactyl\Services\Extensions\ExtensionSettingsRegistry;
use RuntimeException;

final class ModsetController extends Controller
{
    private function authorizeServerFiles(Request $request, Server $server): void
    {
        $user = $request->user();
        abort_unless($user !== null, 403);

        $permissions = $user->permissionsOn($server);
        abort_unless(
            in_array('*', $permissions, true) ||
            in_array('file.update', $permissions, true),
            403
        );
    }

    private function settings(ExtensionSettingsRegistry $registry): array
    {
        $settings = $registry->get('armm-modsets');
        $url = rtrim((string) $settings->get('url'), '/');
        // Restrict outbound destinations to configured HTTPS host, no arbitrary user URL.
        if (!str_starts_with($url, 'https://') || !filter_var($url, FILTER_VALIDATE_URL)) {
            throw new RuntimeException('Configure an HTTPS ARMM URL in Admin > Extensions.');
        }
        $user = (string) $settings->get('username');
        $password = (string) $settings->get('password');
        if ($user === '' || $password === '') {
            throw new RuntimeException('ARMM username or password not configured.');
        }
        return [$url, $user, $password, (string) $settings->get('tls_verify') !== '0', (string) $settings->get('keep_versions') !== '0'];
    }

    /** @return array{0:string,1:array} */
    private function armm(ExtensionSettingsRegistry $registry, string $endpoint): array
    {
        [$url, $user, $password, $verify] = $this->settings($registry);
        $cookie = tempnam(sys_get_temp_dir(), 'armm_');
        if ($cookie === false) throw new RuntimeException('Unable to create temporary cookie storage.');
        chmod($cookie, 0600);
        try {
            $jar = new \GuzzleHttp\Cookie\FileCookieJar($cookie, true);
            $login = Http::withOptions(['verify' => $verify, 'cookies' => $jar, 'allow_redirects' => false])
                ->timeout(15)->acceptJson()->post($url . '/api/auth/login', ['username' => $user, 'password' => $password]);
            if (!$login->successful()) throw new RuntimeException('ARMM authentication failed (HTTP '.$login->status().').');
            $response = Http::withOptions(['verify' => $verify, 'cookies' => $jar, 'allow_redirects' => false])
                ->timeout(20)->acceptJson()->get($url.$endpoint);
            if (!$response->successful()) throw new RuntimeException('ARMM request failed (HTTP '.$response->status().'). Check modset permissions.');
            $data = $response->json();
            if (!is_array($data)) throw new RuntimeException('ARMM returned invalid JSON.');
            return [$url, $data];
        } finally { @unlink($cookie); }
    }

    /** @return list<array{id:int,name:string}> */
    private function availableModsets(ExtensionSettingsRegistry $registry, bool $refresh = false): array
    {
        [$url, $username, $password] = $this->settings($registry);
        $key = 'armm-modsets:modsets:'.hash('sha256', $url."\0".$username."\0".$password);
        if ($refresh) Cache::forget($key);

        return Cache::remember($key, now()->addMinutes(10), function () use ($registry): array {
            [, $data] = $this->armm($registry, '/api/modsets');
            // Accept either a plain list or a wrapped list. Never expose ARMM credentials.
            $list = array_is_list($data) ? $data : ($data['data'] ?? $data['modsets'] ?? $data['items'] ?? []);
            if (!is_array($list)) throw new RuntimeException('ARMM list response has an unknown format.');
            $results = [];
            foreach ($list as $item) {
                if (!is_array($item)) continue;
                $id = $item['id'] ?? $item['modset_id'] ?? null;
                $name = $item['name'] ?? $item['title'] ?? null;
                if ((is_int($id) || (is_string($id) && ctype_digit($id))) && is_string($name)) {
                    $results[] = ['id' => (int) $id, 'name' => $name];
                }
            }
            return $results;
        });
    }

    private function server(int $id): Server
    {
        $server = Server::query()->findOrFail($id);
        abort_unless(str_contains((string) $server->startup, 'ArmaReforgerServer'), 422, 'This server is not an Arma Reforger server.');
        return $server;
    }

    public function assignment(Request $request, Server $server): JsonResponse
    {
        $this->authorizeServerFiles($request, $server);
        $this->server($server->id);
        $record = DB::table('ext_armm_modsets_assignments')->where('server_id', $server->id)->first();
        return response()->json(['modset_id' => $record?->modset_id, 'modset_name' => $record?->modset_name, 'last_count' => $record?->last_count, 'last_synced_at' => $record?->last_synced_at]);
    }

    public function modsets(Request $request, Server $server, ExtensionSettingsRegistry $registry): JsonResponse
    {
        return $this->respondWithModsets($request, $server, $registry, false);
    }

    public function refreshModsets(Request $request, Server $server, ExtensionSettingsRegistry $registry): JsonResponse
    {
        return $this->respondWithModsets($request, $server, $registry, true);
    }

    private function respondWithModsets(Request $request, Server $server, ExtensionSettingsRegistry $registry, bool $refresh): JsonResponse
    {
        $this->authorizeServerFiles($request, $server);
        $this->server($server->id);
        try {
            return response()->json(['modsets' => $this->availableModsets($registry, $refresh)]);
        } catch (\Throwable $e) {
            report($e);
            return response()->json(['error' => $e->getMessage()], 502);
        }
    }

    public function assign(Request $request, Server $server, ExtensionSettingsRegistry $registry): JsonResponse
    {
        $this->authorizeServerFiles($request, $server);
        $data = $request->validate(['modset_id' => ['required','integer','min:1']]);
        $this->server($server->id);
        try {
            $modset = collect($this->availableModsets($registry))->firstWhere('id', (int) $data['modset_id']);
            if ($modset === null) return response()->json(['error' => 'The selected modset is not available. Refresh the modset list and try again.'], 422);
            // Store the mapping and the last known display name in the extension database table.
            DB::table('ext_armm_modsets_assignments')->updateOrInsert(
                ['server_id' => $server->id], ['modset_id' => (int) $data['modset_id'], 'modset_name' => $modset['name'], 'updated_at' => now()]
            );
            return response()->json(['ok' => true, 'server_id' => $server->id, 'modset_id' => (int) $data['modset_id']]);
        } catch (\Throwable $e) {
            report($e);
            return response()->json(['error' => $e->getMessage()], 502);
        }
    }

    public function sync(Request $request, Server $server, ExtensionSettingsRegistry $registry, ReadsFileContents $reader, WritesFileContents $writer): JsonResponse
    {
        $this->authorizeServerFiles($request, $server);
        $this->server($server->id);
        $id = \Illuminate\Support\Facades\DB::table('ext_armm_modsets_assignments')->where('server_id', $server->id)->value('modset_id');
        if (!$id) return response()->json(['error' => 'Assign a modset first.'], 422);
        try {
            [, $mods] = $this->armm($registry, '/api/modsets/'.(int) $id.'/export');
            if (!array_is_list($mods)) throw new RuntimeException('ARMM export must be a JSON array.');
            [, , , , $keepVersions] = $this->settings($registry);
            $clean = [];
            foreach ($mods as $mod) {
                if (!is_array($mod) || !isset($mod['modId']) || !is_string($mod['modId']) || !preg_match('/^[0-9A-Fa-f]{16}$/', $mod['modId'])) {
                    throw new RuntimeException('ARMM export contains an invalid modId.');
                }
                $entry = ['modId' => $mod['modId']];
                if (isset($mod['name']) && is_string($mod['name'])) $entry['name'] = $mod['name'];
                if ($keepVersions && array_key_exists('version', $mod) && is_string($mod['version'])) $entry['version'] = $mod['version'];
                $clean[] = $entry;
            }
            $existing = $reader->read($server, 'config.json');
            $config = json_decode($existing, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($config) || !isset($config['game']) || !is_array($config['game'])) {
                throw new RuntimeException('config.json must contain a game object.');
            }
            $config['game']['mods'] = $clean;
            $encoded = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
            // Protect current configuration; apply only if backup operation succeeds.
            $writer->write($server, 'config.json.armm.bak', $existing);
            $writer->write($server, 'config.json', $encoded);
            \Illuminate\Support\Facades\DB::table('ext_armm_modsets_assignments')->where('server_id', $server->id)->update(['last_synced_at' => now(), 'last_count' => count($clean)]);
            return response()->json(['ok' => true, 'count' => count($clean), 'modset_id' => (int) $id]);
        } catch (\Throwable $e) {
            report($e);
            return response()->json(['error' => $e->getMessage()], 502);
        }
    }
}
