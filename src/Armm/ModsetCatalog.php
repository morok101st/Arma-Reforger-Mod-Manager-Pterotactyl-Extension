<?php
namespace ArmmModsets\Armm;

use Illuminate\Support\Facades\Cache;

final readonly class ModsetCatalog
{
    public function __construct(private ArmmSettings $settings, private ArmmClient $client) {}

    /** @return list<array{id:int,name:string}> */
    public function available(bool $refresh = false): array
    {
        $key = 'armm-modsets:modsets:'.$this->settings->fingerprint();
        if ($refresh) Cache::forget($key);

        return Cache::remember($key, now()->addMinutes(10), function (): array {
            $data = $this->client->get('/api/modsets');
            // Accept either a plain list or a wrapped list.
            $list = array_is_list($data) ? $data : ($data['data'] ?? $data['modsets'] ?? $data['items'] ?? []);
            if (!is_array($list)) throw new ArmmException('ARMM list response has an unknown format.');
            $results = [];
            foreach ($list as $item) {
                if (!is_array($item)) continue;
                $id = $item['id'] ?? $item['modset_id'] ?? null;
                $name = $item['name'] ?? $item['title'] ?? null;
                if ((is_int($id) || (is_string($id) && ctype_digit($id))) && is_string($name)) {
                    $results[] = ['id' => (int) $id, 'name' => mb_substr($name, 0, 255)];
                }
            }

            return $results;
        });
    }

    /** @return array{id:int,name:string}|null */
    public function find(int $id): ?array
    {
        return collect($this->available())->firstWhere('id', $id);
    }
}
