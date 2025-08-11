<?php
declare(strict_types=1);

namespace MapMissingItems\Domain\ItemsXml;

/**
 * Fast membership check for items.xml (single ids and merged ranges).
 */
final class ItemsIndex
{
    /** @var array<int,bool> */
    private array $single = [];
    /** @var array<array{start:int,end:int}> */
    private array $ranges = [];

    /**
     * @param int $id
     * @return void
     */
    public function addSingle(int $id): void
    {
        $this->single[$id] = true;
    }

    /**
     * @param int $from
     * @param int $to
     * @return void
     */
    public function addRange(int $from, int $to): void
    {
        if ($to < $from) { [$from, $to] = [$to, $from]; }
        $this->ranges[] = ['start' => $from, 'end' => $to];
    }

    /**
     * @return void
     */
    public function finalize(): void
    {
        if (!$this->ranges) return;
        usort($this->ranges, fn($a,$b) => $a['start'] <=> $b['start']);
        $merged = [];
        $curr = $this->ranges[0];
        for ($i=1,$n=count($this->ranges); $i<$n; $i++) {
            $r = $this->ranges[$i];
            if ($r['start'] <= $curr['end'] + 1) {
                $curr['end'] = max($curr['end'], $r['end']);
            } else {
                $merged[] = $curr;
                $curr = $r;
            }
        }
        $merged[] = $curr;
        $this->ranges = $merged;
    }

    /**
     * @param int $id
     * @return bool
     */
    public function exists(int $id): bool
    {
        if (isset($this->single[$id])) return true;
        return $this->inRanges($id);
    }

    /**
     * @param int $id
     * @return bool
     */
    private function inRanges(int $id): bool
    {
        $lo=0; $hi=count($this->ranges)-1;
        while ($lo <= $hi) {
            $mid = intdiv($lo+$hi, 2);
            $r = $this->ranges[$mid];
            if ($id < $r['start']) $hi = $mid-1;
            elseif ($id > $r['end']) $lo = $mid+1;
            else return true;
        }
        return false;
    }
}
