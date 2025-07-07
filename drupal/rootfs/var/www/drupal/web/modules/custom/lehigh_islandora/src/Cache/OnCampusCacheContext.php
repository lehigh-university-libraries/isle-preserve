<?php

namespace Drupal\lehigh_islandora\Cache;

use Drupal\Core\Cache\Context\CacheContextInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Allow caching based on on-campus vs off-campus.
 */
class OnCampusCacheContext implements CacheContextInterface {

  /**
   * The request stack service.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  public function __construct(RequestStack $request_stack) {
    $this->requestStack = $request_stack;
  }

  /**
   * {@inheritdoc}
   */
  public static function getLabel() {
    return 'On-campus or off-campus context';
  }

  /**
   * {@inheritdoc}
   */
  public function getContext() {
    $ip = $this->requestStack->getCurrentRequest()->getClientIp();
    return lehigh_islandora_on_campus($ip) ? 'on' : 'off';
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheableMetadata() {
    return new CacheableMetadata();
  }

}
