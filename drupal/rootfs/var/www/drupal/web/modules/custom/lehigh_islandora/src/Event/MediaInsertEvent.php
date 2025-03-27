<?php

namespace Drupal\lehigh_islandora\Event;

use Symfony\Contracts\EventDispatcher\Event;
use Drupal\media\Entity\Media;

/**
 * Defines the custom media insert event.
 */
class MediaInsertEvent extends Event {

  /**
   * Event name.
   */
  public const NAME = 'lehigh_islandora.media_insert';

  /**
   * The media entity.
   *
   * @var \Drupal\media\Entity\Media
   */
  protected $media;

  /**
   * Constructs a new MediaInsertEvent.
   *
   * @param \Drupal\media\Entity\Media $media
   *   The media entity.
   */
  public function __construct(Media $media) {
    $this->media = $media;
  }

  /**
   * Gets the media entity.
   *
   * @return \Drupal\media\Entity\Media
   *   The media entity.
   */
  public function getMedia() {
    return $this->media;
  }

}
