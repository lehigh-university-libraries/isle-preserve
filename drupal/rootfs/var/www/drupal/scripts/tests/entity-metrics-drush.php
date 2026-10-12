<?php

// Run after Composer patches apply: php scripts/tests/entity-metrics-drush.php.
$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
require $root . '/web/modules/contrib/entity_metrics/src/GeolocationBackfill.php';
require $root . '/web/modules/contrib/entity_metrics/src/Commands/GeolocationCommands.php';
require $root . '/web/modules/contrib/geoip_autoupdate/src/GeoIpUpdaterService.php';

use Drupal\entity_metrics\Commands\GeolocationCommands;
use Drupal\entity_metrics\GeolocationBackfill;
use Drupal\geoip_autoupdate\GeoIpUpdaterService;
use Drush\Runtime\LegacyServiceInstantiator;
use League\Container\Container;
use Psr\Log\NullLogger;

$backfill = (new ReflectionClass(GeolocationBackfill::class))->newInstanceWithoutConstructor();
$updater = (new ReflectionClass(GeoIpUpdaterService::class))->newInstanceWithoutConstructor();
foreach ([NULL, $updater] as $available) {
  $container = new Container();
  $container->add('entity_metrics.geolocation', $backfill);
  if ($available) {
    $container->add('geoip_autoupdate.updater', $available);
  }
  $loader = new LegacyServiceInstantiator($container, new NullLogger());
  $loader->loadServiceFiles([$root . '/web/modules/contrib/entity_metrics/drush.services.yml']);
}

try {
  (new GeolocationCommands($backfill, NULL))->update();
  throw new LogicException('Expected a missing updater error.');
}
catch (RuntimeException $error) {
  if ($error->getMessage() !== 'Enable GeoIP Auto-Update and rebuild caches before updating the database.') {
    throw $error;
  }
}
echo "Drush command discovery works with and without the GeoIP updater.\n";
