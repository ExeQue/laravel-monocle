<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Enums;

/**
 * How the dashboard formats a summary total.
 */
enum Unit: string
{
    case Milliseconds = 'ms';
    case Bytes = 'bytes';
    case Megabytes = 'mb';
    case Percent = 'percent';
}
