<?php

namespace Drupal\lehigh_islandora\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueWorkerManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

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
    $queues = lehigh_islandora_get_queue_depth();
    $labels = [
      'islandora-connector-whisper' => 'VTT Transcripts',
      'islandora-merge-pdf' => 'Paged Content PDFs',
      'islandora-openai-htr' => 'Handwritten Text OCR',
      'islandora-connector-houdini' => 'Image derivative (e.g. thumbnails, TIFF->JP2)',
      'islandora-pdf-coverpage' => 'Add Coverpage to PDF',
      'islandora-connector-libreoffice' => 'Microsoft Document to PDF',
      'islandora-connector-homarus' => 'Video thumbnail',
      'islandora-connector-fits' => 'FITS XML generation',
      'islandora-connector-ocr' => 'Extract OCR from image or PDF',
    ];
    $header = [
      'Derivative Type',
      'Items in queue',
    ];
    $rows = [];
    foreach ($labels as $queue => $label) {
      if (empty($queues[$queue])) {
        continue;
      }
      $rows[] = [
        $label,
        $queues[$queue],
      ];
    }
    $form['information'] = [
      '#type' => 'table',
      '#prefix' => '<h3>Queue</h3>Items that are currently being processed',
      '#header' => $header,
      '#rows' => $rows,
      '#empty' => $this->t('No items currently being processed.'),
    ];

    $results = \Drupal::database()->query('SELECT SUBSTRING(`name`, 1, LENGTH(`name`) - LENGTH(SUBSTRING_INDEX(`name`, \'_\', -1)) - 1) AS `action_name`,
      COUNT(*) AS count
      FROM `queue`
      GROUP BY `action_name`');
    $options = [];
    $entity_type_manager = \Drupal::entityTypeManager();
    $action_storage = $entity_type_manager->getStorage('action');

    foreach ($results as $row) {
      $action = $action_storage->load($row->action_name);
      if (empty($action)) {
        continue;
      }
      $options[] = [
        'action' => $action->label(),
        'count' => $row->count,
      ];
    }
    $form['islandora_actions'] = [
      '#type' => 'tableselect',
      '#prefix' => '<br><br><h3>Deadletter queue</h3>
      <p>Items that have been attempted before, but have failed at least once.</p>
      <p>These likely need manual intervention/troubleshooting to resolve</p>',
      '#header' => [
        'action' => $this->t('Action'),
        'count' => $this->t('Events'),
      ],
      '#options' => $options,
      '#empty' => $this->t('No items in dead letter queue'),
    ];

    $form['actions']['#type'] = 'actions';
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Process events (To be implemented)'),
      '#button_type' => 'primary',
      '#disabled' => TRUE,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
  }

}
