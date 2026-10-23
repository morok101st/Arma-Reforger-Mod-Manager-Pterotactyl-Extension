<?php
namespace ArmmModsets\Armm;

use Illuminate\Support\Facades\Cache;
use JsonException;
use Pterodactyl\Contracts\Files\ReadsFileContents;
use Pterodactyl\Contracts\Files\WritesFileContents;
use Pterodactyl\Contracts\Servers\ReadsServerState;
use Pterodactyl\Models\Server;

final readonly class ConfigSynchronizer
{
    private const MAX_MODS = 1000;

    public function __construct(
        private ArmmSettings $settings,
        private ArmmClient $client,
        private ReadsServerState $state,
        private ReadsFileContents $reader,
        private WritesFileContents $writer,
    ) {}

    /**
     * Replace game.mods in config.json with the export of a modset and keep a
     * timestamped backup of the previous file. Returns the number of mods written.
     */
    public function sync(Server $server, int $modsetId): int
    {
        $lock = Cache::lock('armm-modsets:sync:'.$server->id, 120);
        if (!$lock->get()) {
            throw new ArmmException('A synchronization for this server is already running.', 409);
        }
        try {
            if (!in_array($this->state->read($server)->state, ['offline', 'stopped'], true)) {
                throw new ArmmException('Stop the server before synchronizing config.json.', 409);
            }
            $mods = $this->exportedMods($modsetId);
            $existing = $this->reader->read($server, 'config.json');
            try {
                $config = json_decode($existing, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                throw new ArmmException('config.json is not valid JSON.', 422);
            }
            if (!is_array($config) || !isset($config['game']) || !is_array($config['game'])) {
                throw new ArmmException('config.json must contain a game object.', 422);
            }
            $config['game']['mods'] = $mods;
            $encoded = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
            // Protect the current configuration; apply only if the backup succeeds.
            $this->writer->write($server, 'config.json.armm-'.now()->format('Ymd-His').'.bak', $existing);
            $this->writer->write($server, 'config.json', $encoded);

            return count($mods);
        } finally {
            $lock->release();
        }
    }

    /** @return list<array{modId:string,name?:string,version?:string}> */
    private function exportedMods(int $modsetId): array
    {
        $mods = $this->client->get('/api/modsets/'.$modsetId.'/export');
        if (!array_is_list($mods)) throw new ArmmException('ARMM export must be a JSON array.');
        if (count($mods) > self::MAX_MODS) throw new ArmmException('ARMM export contains more than '.self::MAX_MODS.' mods.');
        $clean = [];
        foreach ($mods as $mod) {
            if (!is_array($mod) || !isset($mod['modId']) || !is_string($mod['modId']) || !preg_match('/^[0-9A-Fa-f]{16}$/', $mod['modId'])) {
                throw new ArmmException('ARMM export contains an invalid modId.');
            }
            $entry = ['modId' => $mod['modId']];
            if (isset($mod['name']) && is_string($mod['name'])) $entry['name'] = mb_substr($mod['name'], 0, 255);
            if ($this->settings->keepVersions && isset($mod['version']) && is_string($mod['version'])) {
                if (!preg_match('/^[0-9A-Za-z.+_-]{1,64}$/', $mod['version'])) {
                    throw new ArmmException('ARMM export contains an invalid version for mod '.$mod['modId'].'.');
                }
                $entry['version'] = $mod['version'];
            }
            $clean[] = $entry;
        }

        return $clean;
    }
}
