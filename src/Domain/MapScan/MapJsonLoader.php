<?php
declare(strict_types=1);

namespace MapMissingItems\Domain\MapScan;

/**
 * Parses OTBM2JSON structure (as seen in examples/OTBM.json) and returns a flat list of items with absolute positions.
 */
final class MapJsonLoader
{
    /**
     * @return array<int, array{id:int, x:int, y:int, z:int}>
     *
     * @param string $json
     * @return array
     * @throws \JsonException
     */
    public function loadItems(string $json): array
    {
        $root = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        if (!isset($root['data']['nodes']) || !is_array($root['data']['nodes'])) {
            return [];
        }

        $out = [];
        foreach ($root['data']['nodes'] as $node) {
            if (!isset($node['features']) || !is_array($node['features'])) {
                continue;
            }
            foreach ($node['features'] as $feature) {
                $fx = isset($feature['x']) ? (int)$feature['x'] : null;
                $fy = isset($feature['y']) ? (int)$feature['y'] : null;
                $fz = isset($feature['z']) ? (int)$feature['z'] : null;
                if ($fx === null || $fy === null || $fz === null) {
                    continue; // only area features with x,y,z
                }
                if (!isset($feature['tiles']) || !is_array($feature['tiles'])) {
                    continue;
                }
                foreach ($feature['tiles'] as $tile) {
                    if (!isset($tile['x'], $tile['y'])) {
                        continue;
                    }
                    $tx = (int)$tile['x'];
                    $ty = (int)$tile['y'];
                    $absX = $fx + $tx;
                    $absY = $fy + $ty;
                    if (!isset($tile['items']) || !is_array($tile['items'])) {
                        continue;
                    }
                    foreach ($tile['items'] as $item) {
                        $this->collectItem($item, $absX, $absY, $fz, $out);
                    }
                }
            }
        }
        return $out;
    }

    /**
     * Recursively collects item id and nested content
     *
     * @param array $item
     * @param int $x
     * @param int $y
     * @param int $z
     * @param array $out
     * @return void
     */
    private function collectItem(array $item, int $x, int $y, int $z, array &$out): void
    {
        if (isset($item['id'])) {
            $out[] = ['id' => (int)$item['id'], 'x' => $x, 'y' => $y, 'z' => $z];
        }
        if (isset($item['content']) && is_array($item['content'])) {
            foreach ($item['content'] as $inside) {
                if (is_array($inside)) {
                    $this->collectItem($inside, $x, $y, $z, $out);
                }
            }
        }
    }
}
