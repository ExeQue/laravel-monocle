<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Enums;

enum EntryType: string
{
    case Request = 'request';
    case Job = 'job';
    case Command = 'command';
    case Mcp = 'mcp';
}
