<?php

declare(strict_types=1);

namespace Laravel\Folio\Events;

/**
 * Stand-in for Folio's event, so the request lens can be tested without the
 * package. The real event carries a MatchedView and a MountPath.
 */
class ViewMatched
{
    public function __construct(public object $matchedView, public object $mountPath)
    {
    }
}
