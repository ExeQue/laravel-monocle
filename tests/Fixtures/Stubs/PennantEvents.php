<?php

declare(strict_types=1);

namespace Laravel\Pennant\Events;

/*
 * Stand-ins for Pennant's events, so the feature flags lens can be tested
 * without the package. Same public properties as the real ones.
 */

class FeatureRetrieved
{
    public function __construct(public $feature, public $scope, public $value)
    {
    }
}

class FeatureResolved
{
    public function __construct(public $feature, public $scope, public $value)
    {
    }
}

class UnknownFeatureResolved
{
    public function __construct(public $feature, public $scope)
    {
    }
}

class FeatureUpdated
{
    public function __construct(public $feature, public $scope, public $value)
    {
    }
}

class FeatureDeleted
{
    public function __construct(public $feature, public $scope)
    {
    }
}
