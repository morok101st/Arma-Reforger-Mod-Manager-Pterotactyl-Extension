<?php
namespace ArmmModsets\Armm;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use stdClass;

final class Assignments
{
    public function find(int $serverId): ?stdClass
    {
        return $this->table()->where('server_id', $serverId)->first();
    }

    public function assign(int $serverId, int $modsetId, string $modsetName): void
    {
        $this->table()->updateOrInsert(
            ['server_id' => $serverId],
            ['modset_id' => $modsetId, 'modset_name' => $modsetName, 'updated_at' => now()],
        );
    }

    public function markSynced(int $serverId, int $count): void
    {
        $this->table()->where('server_id', $serverId)->update(['last_synced_at' => now(), 'last_count' => $count]);
    }

    private function table(): Builder
    {
        return DB::table('ext_armm_modsets_assignments');
    }
}
