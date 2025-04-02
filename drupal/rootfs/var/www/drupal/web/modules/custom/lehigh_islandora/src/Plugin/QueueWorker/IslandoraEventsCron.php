<?php

namespace Drupal\lehigh_islandora\Plugin\QueueWorker;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\UserSession;
use Drupal\user\Entity\User;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Define a queue worker to emit events on Drupal cron.
 *
 * @QueueWorker(
 *   id = "lehigh_islandora_events",
 *   title = @Translation("Lehigh Islandora Events Processing (cron)"),
 *   cron = {"time" = 60}
 * )
 */
class IslandoraEventsCron extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  const QUEUENAME = 'lehigh_islandora_events';

  /**
   * A connection to Drupal's database.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $db;

  /**
   * The logger channel.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a new IslandoraEventsCron object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin ID for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Database\Connection $db
   *   A connection to the database.
   * @param \Drupal\Core\Logger\LoggerChannelInterface $logger
   *   The logger channel.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, Connection $db, LoggerChannelInterface $logger, EntityTypeManagerInterface $entityTypeManager) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->db = $db;
    $this->logger = $logger;
    $this->entityTypeManager = $entityTypeManager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('database'),
      $container->get('logger.factory')->get('lehigh_islandora'),
      $container->get('entity_type.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data) {

    $action_id = $data['action'];
    $entity_type = $data['entity_type'];
    $entity_id = $data['entity_id'];

    $entity_storage = $this->entityTypeManager->getStorage($entity_type);
    $entity = $entity_storage->load($entity_id);
    if (!$entity) {
      $this->logger->error('Entity of type @entity_type with ID @id not found.', [
        '@entity_type' => $entity_type,
        '@id' => $entity_id,
      ]);
      return;
    }

    $action_storage = $this->entityTypeManager->getStorage('action');
    $action = $action_storage->load($action_id);
    if (!$action) {
      $this->logger->error('Unknown action: @action', ['@action' => $action_id]);
      return;
    }
    $accountSwitcher = \Drupal::service('account_switcher');
    $account = User::load($entity->getOwnerId());
    $userSession = new UserSession([
      'uid'   => $account->id(),
      'name'  => $account->getDisplayName(),
      'roles' => $account->getRoles(),
    ]);
    $accountSwitcher->switchTo($userSession);
    $action->execute([$entity]);
    $accountSwitcher->switchBack();
  }

  /**
   * Insert an item into the event queue.
   */
  public static function insertItem($entity_type, $entity_id, $action, $unique = FALSE) {
    $event = [
      'action' => $action,
      'entity_type' => $entity_type,
      'entity_id' => $entity_id,
    ];
    if ($unique) {
      $eventExists = \Drupal::database()->query("SELECT item_id FROM {queue}
        WHERE name = :name AND data = :data", [
          ':data' => serialize($event),
          ':name' => self::QUEUENAME,
        ])->fetchField();
      if ($eventExists) {
        return;
      }
    }
    $queue = \Drupal::queue(self::QUEUENAME);
    $queue->createItem($event);
  }

}
