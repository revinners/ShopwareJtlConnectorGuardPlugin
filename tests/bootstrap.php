<?php

declare(strict_types=1);

/*
 * Unit tests only need the class loader: the plugin declares shopware/core as a
 * dependency, so `composer install` inside the plugin directory provides every
 * Shopware class the code touches. No kernel, no database.
 */
require __DIR__ . '/../vendor/autoload.php';
