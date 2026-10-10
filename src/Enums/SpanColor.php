<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Enums;

/**
 * Colours a span can be drawn with on the dashboard timeline. They follow the
 * dashboard theme in light and dark mode; any hex colour works as well.
 */
enum SpanColor: string
{
    case Bronze = 'bronze';
    case Teal = 'teal';
    case Lavender = 'lavender';
    case Green = 'green';
    case Blue = 'blue';
    case Red = 'red';
    case Pink = 'pink';
    case Grey = 'grey';
}
