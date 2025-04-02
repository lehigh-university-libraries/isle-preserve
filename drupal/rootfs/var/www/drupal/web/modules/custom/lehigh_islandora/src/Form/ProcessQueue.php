<?php

namespace Drupal\lehigh_islandora\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueWorkerManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Utility\Error;
use Drupal\Core\Url;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Queue\SuspendQueueException;
use Drupal\lehigh_islandora\Plugin\QueueWorker\IslandoraEventsCron;

/**
 * Process items in the queue.
 */
class ProcessQueue extends FormBase {

  /**
   * The queue factory.
   *
   * @var \Drupal\Core\Queue\QueueFactory
   */
  protected $queueFactory;

  /**
   * The queue manager.
   *
   * @var \Drupal\Core\Queue\QueueWorkerManagerInterface
   */
  protected $queueManager;

  /**
   * The messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * The logger channel.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;

  /**
   * Constructs a ProcessQueue object.
   *
   * @param \Drupal\Core\Queue\QueueFactory $queue
   *   The queue factory.
   * @param \Drupal\Core\Queue\QueueWorkerManagerInterface $queue_manager
   *   The queue worker manager.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger channel factory.
   */
  public function __construct(QueueFactory $queue, QueueWorkerManagerInterface $queue_manager, MessengerInterface $messenger, LoggerChannelFactoryInterface $logger_factory) {
    $this->queueFactory = $queue;
    $this->queueManager = $queue_manager;
    $this->messenger = $messenger;
    $this->loggerFactory = $logger_factory;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('queue'),
      $container->get('plugin.manager.queue_worker'),
      $container->get('messenger'),
      $container->get('logger.factory')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'lehigh_islandora_process_queue_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['info'] = [
      '#type' => 'markup',
      '#markup' => $this->t('<p>Submitting this form will process all events in the queue.</p>
      <p>This will automatically be done on cron, but you can perform it manually here.</p>'),
    ];
    $form['actions']['#type'] = 'actions';
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Process events'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $queue = $this->queueFactory->get(IslandoraEventsCron::QUEUENAME);
    $operations = [];
    while ($event = $queue->claimItem()) {
      $operations[] = [
        'Drupal\lehigh_islandora\Form\ProcessQueue::processQueue',
        [$event],
      ];
    }
    $batch = [
      'operations' => $operations,
      'finished' => 'Drupal\lehigh_islandora\Form\ProcessQueue::batchFinished',
      'title' => $this->t('Emitting events'),
      'init_message' => $this->t('Event emit process is starting.'),
      'progress_message' => $this->t('Processed @current events out of @total.'),
      'error_message' => $this->t('Event emission has encountered an error.'),
    ];

    batch_set($batch);
  }

  /**
   * Processes an individual queue item.
   *
   * @param object $event
   *   The queue item.
   */
  public static function processQueue($event) {
    $queue = \Drupal::service('queue')->get('lehigh_islandora_events');
    $queue_worker = \Drupal::service('plugin.manager.queue_worker')->createInstance('lehigh_islandora_events');
    try {
      $queue_worker->processItem($event->data);
      $queue->deleteItem($event);
    }
    catch (SuspendQueueException $e) {
      $queue->releaseItem($event);
      $logger = \Drupal::logger('lehigh_islandora');
      Error::logException($logger, $e);
    }
    catch (\Exception $e) {
      $logger = \Drupal::logger('lehigh_islandora');
      Error::logException($logger, $e);
    }
  }

  /**
   * Called when the batch processing is finished.
   *
   * @param bool $success
   *   Whether the batch completed successfully.
   * @param array $results
   *   An array of results from the batch operations.
   * @param array $operations
   *   An array of operations that were run.
   */
  public static function batchFinished($success, $results, $operations) {
    if ($success) {
      \Drupal::messenger()->addStatus('Successfully processed all events in the queue.');
      return;
    }

    $url_options = [
      'absolute' => TRUE,
    ];
    $t_args = [
      ':link' => Url::fromRoute('dblog.overview', [], $url_options)->toString(),
    ];
    \Drupal::messenger()->addError(t('Could not emit events. Check your <a href=":link">Recent log messages</a>', $t_args));
  }

}
