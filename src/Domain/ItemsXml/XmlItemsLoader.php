<?php
declare(strict_types=1);

namespace MapMissingItems\Domain\ItemsXml;

use XMLReader;
use RuntimeException;

final class XmlItemsLoader
{
    /**
     * Streams items.xml and builds an ItemsIndex.
     *
     * @param string $itemsXmlPath
     * @return ItemsIndex
     */
    public function load(string $itemsXmlPath): ItemsIndex
    {
        $index = new ItemsIndex();
        $reader = new XMLReader();
        if (!$reader->open($itemsXmlPath)) {
            throw new RuntimeException('Cannot open items.xml: ' . $itemsXmlPath);
        }

        while ($reader->read()) {
            if ($reader->nodeType !== XMLReader::ELEMENT || $reader->name !== 'item') continue;
            $id = $reader->getAttribute('id');
            $from = $reader->getAttribute('fromid');
            $to = $reader->getAttribute('toid');
            if ($id !== null) {
                $index->addSingle((int)$id);
            } elseif ($from !== null && $to !== null) {
                $index->addRange((int)$from, (int)$to);
            }
        }
        $reader->close();
        $index->finalize();
        return $index;
    }
}
