<?php

declare(strict_types=1);

/*
 * Unit tests only need the class loader: the plugin declares shopware/core as a
 * dependency, so `composer install` inside the plugin directory provides every
 * Shopware class the code touches. No kernel, no database.
 */
require __DIR__ . '/../vendor/autoload.php';

/*
 * The plugin's own services (GuardConfigProvider, ConnectorSourceDetector, CustomerStateLoader,
 * GuardLogger, ...) are declared `final` by design (see style guide). PHP forbids subclassing a
 * final class, which is how PHPUnit's MockObject generator normally builds a test double - so
 * without this, createMock() on any of them throws ClassIsFinalException. dg/bypass-finals
 * strips the `final` keyword at include time for test doubling only; it never touches the
 * shipped plugin code (production behaviour, including finality, is unchanged outside the test
 * process).
 */
\DG\BypassFinals::enable();
