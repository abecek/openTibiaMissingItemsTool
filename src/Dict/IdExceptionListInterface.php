<?php
declare(strict_types=1);

namespace MapMissingItems\Dict;

interface IdExceptionListInterface
{
    public const array IDS_TO_SKIP = [
        13031, // nothing, blocking walking item
        8046, // something sparkling
        8047, // something sparkling
        8029, // nothing
        7288, // nothing, prevent moving item below it (suspicion)
    ];
}