<?php

use Drupal\user\Entity\User;
use Drupal\node\Entity\Node;
use Drupal\islandora\Event\StompHeaderEvent;
use Drupal\islandora\Event\StompHeaderEventException;
use Stomp\Exception\StompException;
use Stomp\Transport\Message;

$eventGenerator = \Drupal::service('islandora.eventgenerator');
$stomp = \Drupal::service('islandora.stomp');
$logger = \Drupal::logger('lehigh_islandora');
$eventDispatcher = Drupal::service('event_dispatcher');

try {
  $user = User::load(1);
  $entity = Node::load(1);
  $data = [
    'queue' => 'islandora-cache-warmer',
    'event' => 'Create',
  ];

  $event = $eventDispatcher->dispatch(
    new StompHeaderEvent($entity, $user, $data, $data),
    StompHeaderEvent::EVENT_NAME
  );

  $json = $eventGenerator->generateEvent($entity, $user, $data);
  $eventMessage = json_decode($json);
  // add the special target to crawl the entire site
  $eventMessage->target = 'all';
  $json = json_encode($eventMessage);
  $message = new Message(
    $json,
    $event->getHeaders()->all()
  );
}
catch (StompHeaderEventException $e) {
  $logger->error($e->getMessage());
  return;
}
catch (StompException $e) {
  $logger->error("Unable to connect to JMS Broker: @msg", ["@msg" => $e->getMessage()]);
  return;
}
catch (\RuntimeException $e) {
  $logger->error('Error generating event: @msg', ['@msg' => $e->getMessage()]);
  return;
}

// Send the message.
try {
  $stomp->begin();
  $stomp->send("islandora-cache-warmer", $message);
  $stomp->commit();
}
catch (StompException $e) {
  // Log it.
  $logger->error(
    'Error publishing message: @msg',
    ['@msg' => $e->getMessage()]
  );
}
