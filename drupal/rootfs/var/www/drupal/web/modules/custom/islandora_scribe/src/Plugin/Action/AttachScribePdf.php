<?php

namespace Drupal\islandora_scribe\Plugin\Action;

/**
 * Requests the Scribe PDF microservice through the node-event broker.
 *
 * @Action(
 *   id = "attach_scribe_pdf",
 *   label = @Translation("Attach searchable PDF from Scribe"),
 *   type = "node"
 * )
 */
final class AttachScribePdf extends BookAction {

  protected const MODE = 'pdf';

}
