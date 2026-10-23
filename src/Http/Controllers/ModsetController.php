<?php
namespace ArmmModsets\Http\Controllers;

use ArmmModsets\Armm\ArmmException;
use ArmmModsets\Armm\ArmmSettings;
use ArmmModsets\Armm\Assignments;
use ArmmModsets\Armm\ConfigSynchronizer;
use ArmmModsets\Armm\ModsetCatalog;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;
use Pterodactyl\Models\Server;
use Throwable;

final class ModsetController extends Controller
{
    public function __construct(private readonly Assignments $assignments) {}

    public function assignment(Request $request, Server $server): JsonResponse
    {
        $this->guard($request, $server);
        $record = $this->assignments->find($server->id);

        return response()->json([
            'modset_id' => $record?->modset_id,
            'modset_name' => $record?->modset_name,
            'last_count' => $record?->last_count,
            'last_synced_at' => $record?->last_synced_at,
        ]);
    }

    public function modsets(Request $request, Server $server): JsonResponse
    {
        return $this->respondWithModsets($request, $server, false);
    }

    public function refreshModsets(Request $request, Server $server): JsonResponse
    {
        return $this->respondWithModsets($request, $server, true);
    }

    public function assign(Request $request, Server $server): JsonResponse
    {
        $this->guard($request, $server);
        $modsetId = (int) $request->validate(['modset_id' => ['required', 'integer', 'min:1']])['modset_id'];

        return $this->handle(function () use ($server, $modsetId): JsonResponse {
            $modset = app(ModsetCatalog::class)->find($modsetId);
            if ($modset === null) {
                throw new ArmmException('The selected modset is not available. Refresh the modset list and try again.', 422);
            }
            $this->assignments->assign($server->id, $modsetId, $modset['name']);

            return response()->json(['ok' => true, 'server_id' => $server->id, 'modset_id' => $modsetId]);
        });
    }

    public function sync(Request $request, Server $server): JsonResponse
    {
        $this->guard($request, $server);
        $modsetId = (int) $this->assignments->find($server->id)?->modset_id;
        if ($modsetId < 1) return response()->json(['error' => 'Assign a modset first.'], 422);

        return $this->handle(function () use ($server, $modsetId): JsonResponse {
            $count = app(ConfigSynchronizer::class)->sync($server, $modsetId);
            $this->assignments->markSynced($server->id, $count);

            return response()->json(['ok' => true, 'count' => $count, 'modset_id' => $modsetId]);
        });
    }

    private function respondWithModsets(Request $request, Server $server, bool $refresh): JsonResponse
    {
        $this->guard($request, $server);

        return $this->handle(fn (): JsonResponse => response()->json([
            'modsets' => app(ModsetCatalog::class)->available($refresh),
            'tls_verify' => app(ArmmSettings::class)->verifyTls,
        ]));
    }

    /** Require file.update on an Arma Reforger server for every action. */
    private function guard(Request $request, Server $server): void
    {
        $user = $request->user();
        abort_unless($user !== null, 403);
        $permissions = $user->permissionsOn($server);
        abort_unless(in_array('*', $permissions, true) || in_array('file.update', $permissions, true), 403);
        abort_unless(str_contains((string) $server->startup, 'ArmaReforgerServer'), 422, 'This server is not an Arma Reforger server.');
    }

    /** Only ArmmException messages reach the client; anything else is logged and answered generically. */
    private function handle(Closure $action): JsonResponse
    {
        try {
            return $action();
        } catch (ArmmException $e) {
            return response()->json(['error' => $e->getMessage()], $e->status());
        } catch (DaemonConnectionException $e) {
            report($e);
            return response()->json(['error' => 'Wings could not be reached for this server.'], 502);
        } catch (Throwable $e) {
            report($e);
            return response()->json(['error' => 'Unexpected error. Details were written to the panel log.'], 500);
        }
    }
}
