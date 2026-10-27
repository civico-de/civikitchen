<?php

declare(strict_types=1);

use CiviKitchen\Mago\CiviKitchenExtension;
use CiviKitchen\Mago\Options;
use Mago\Sdk\Worker;

// stdout carries mago's frames; a PHP error printed there breaks the protocol.
ini_set('display_errors', 'stderr');

require __DIR__ . '/vendor/autoload.php';

(new Worker(CiviKitchenExtension::create(Options::fromArguments(array_slice($_SERVER['argv'], 1)))))->run();
