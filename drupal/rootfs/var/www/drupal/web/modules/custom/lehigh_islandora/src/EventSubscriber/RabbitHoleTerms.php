<?php

declare(strict_types=1);

namespace Drupal\lehigh_islandora\EventSubscriber;

use Drupal\Core\Url;
use Drupal\taxonomy\Entity\Term;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Redirect terms from their default display.
 */
final class RabbitHoleTerms implements EventSubscriberInterface {

  /**
   * Redirect taxonomy terms to the browse page.
   *
   * @param \Symfony\Component\HttpKernel\Event\RequestEvent $event
   *   The event to process.
   */
  public function redirectTerms(RequestEvent $event) : void {
    // See if we're dealing with a canonical node request.
    $request = $event->getRequest();
    $path = $request->getPathInfo();
    $regex = '/^\/taxonomy\/term\/\d+$/';
    if (preg_match($regex, $path)) {
      // Don't redirect REST CUD requests.
      if (!$request->isMethod('GET')) {
        return;
      }

      // Don't redirect ?_format= requests.
      $format = $request->query->get('_format');
      if ($format) {
        return;
      }

      // Get the term ID from the path.
      $path = ltrim($path, '/');
      $path_parts = explode('/', $path);
      if (isset($path_parts[2]) && is_numeric($path_parts[2])) {
        $tid = $path_parts[2];
      }
      else {
        return;
      }

      $term = Term::load($tid);
      if (!$term) {
        return;
      }
      $options = [];
      $facet = '';
      switch ($term->bundle()) {
        case 'subject_lcsh':
        case 'keywords':
          $facet = $term->bundle();
          break;

        case 'islandora_models':
          $facet = 'model';
          break;
      }
      if ($facet != '') {
        $options['query']['f'][] = "$facet:$tid";
      }
      $url = Url::fromRoute('view.browse.main', ['node' => -1], $options);
      $redirect = new RedirectResponse($url->toString());
      $event->setResponse($redirect);
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      KernelEvents::REQUEST => [
      ['redirectTerms'],
      ],
    ];
  }

}
