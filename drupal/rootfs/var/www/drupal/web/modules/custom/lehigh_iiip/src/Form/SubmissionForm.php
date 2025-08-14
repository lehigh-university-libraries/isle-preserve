<?php

declare(strict_types=1);

namespace Drupal\lehigh_iiip\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Node\Entity\Node;
use Drupal\media\Entity\Media;
use Drupal\Core\Session\AccountProxyInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a Lehigh Iacocca International Internship Program submission form.
 */
final class SubmissionForm extends FormBase {
  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * Constructs a new MyForm object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Session\AccountProxyInterface $current_user
   *   The current user.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, AccountProxyInterface $current_user) {
    $this->entityTypeManager = $entity_type_manager;
    $this->currentUser = $current_user;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('current_user')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'lehigh_iiip_submission';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $required = TRUE;
    if (\Drupal::currentUser()->isAnonymous()) {
      $key = getenv('GOOGLE_MAPS_API_KEY');
      $form['#attached']['html_head'][] = [
        [
          '#type' => 'html_tag',
          '#tag' => 'script',
          '#attributes' => [
            'src' => "https://maps.googleapis.com/maps/api/js?key=$key&libraries=places&loading=async",
            'async' => TRUE,
            'defer' => TRUE,
          ],
        ],
        'google_maps_api',
      ];
    }
    $form['student_details'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#attributes' => [
        'class' => ['card', 'mb-4'],
      ],
    ];

    $form['student_details']['header'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#attributes' => [
        'class' => ['card-header', 'bg-light'],
      ],
    ];

    $form['student_details']['header']['title'] = [
      '#type' => 'html_tag',
      '#tag' => 'h3',
      '#attributes' => [
        'class' => ['text-dark'],
      ],
      '#value' => 'Student Details',
    ];

    $form['student_details']['body'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#attributes' => [
        'class' => ['card-body'],
      ],
    ];

    $form['student_details']['body']['row'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#attributes' => [
        'class' => ['row'],
      ],
    ];

    $form['student_details']['body']['row']['name_col'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#attributes' => [
        'class' => ['col-md-7'],
      ],
    ];

    $form['student_details']['body']['row']['name_col']['student_name'] = [
      '#type' => 'textfield',
      '#title' => 'Student Name:',
      '#attributes' => [
        'class' => ['form-control', 'mb-3'],
      ],
      '#required' => $required,
    ];

    $form['student_details']['body']['row']['name_col']['student_major'] = [
      '#type' => 'textfield',
      '#title' => 'Student Major:',
      '#attributes' => [
        'class' => ['form-control', 'mb-3'],
      ],
      '#required' => $required,
    ];

    $form['student_details']['body']['row']['photo_col'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#attributes' => [
        'class' => ['col-md-4', 'text-center'],
      ],
    ];

    $extensions = 'jpg jpeg png gif';
    $form['student_details']['body']['row']['photo_col']['student_photo'] = [
      '#type' => 'managed_file',
      '#title' => 'Profile Picture',
      '#description' => $extensions,
      '#upload_location' => 'fedora://iiip/student_photos/' . $this->currentUser->id(),
      '#upload_validators' => [
        'FileExtension' => ['extensions' => $extensions],
      ],
      '#attributes' => [
        'class' => ['btn', 'btn-outline-secondary', 'btn-sm'],
      ],
    ];

    $form['internship_details'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#attributes' => [
        'class' => ['card', 'mb-4'],
      ],
    ];

    $form['internship_details']['header'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#attributes' => [
        'class' => ['card-header', 'bg-light'],
      ],
    ];

    $form['internship_details']['header']['title'] = [
      '#type' => 'html_tag',
      '#tag' => 'h3',
      '#attributes' => [
        'class' => ['text-dark'],
      ],
      '#value' => 'Internship Details',
    ];

    $form['internship_details']['body'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#attributes' => [
        'class' => ['card-body'],
      ],
    ];

    $form['internship_details']['body']['row'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#attributes' => [
        'class' => ['row'],
      ],
    ];

    // Left column for text fields.
    $form['internship_details']['body']['row']['fields_col'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#attributes' => [
        'class' => ['col-md-7'],
      ],
    ];

    $form['internship_details']['body']['row']['fields_col']['position_title'] = [
      '#type' => 'textfield',
      '#title' => 'Internship Position Title:',
      '#attributes' => [
        'class' => ['form-control', 'mb-3'],
      ],
      '#required' => $required,
    ];

    $form['internship_details']['body']['row']['fields_col']['internship_year'] = [
      '#type' => 'number',
      '#title' => 'Internship Year:',
      '#attributes' => [
        'class' => ['form-control', 'mb-3'],
      ],
      '#required' => $required,
      '#min' => '2000',
      '#max' => date('Y'),
      '#step' => 1,
    ];

    $form['internship_details']['body']['row']['fields_col']['company'] = [
      '#type' => 'textfield',
      '#title' => 'Internship Company:',
      '#attributes' => [
        'class' => ['form-control', 'mb-3'],
      ],
      '#required' => $required,
    ];

    $form['internship_details']['body']['row']['fields_col']['country'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#attributes' => [
        'class' => ['mb-3'],
      ],
    ];

    $form['internship_details']['body']['row']['fields_col']['country']['label'] = [
      '#type' => 'html_tag',
      '#tag' => 'label',
      '#attributes' => [
        'for' => 'internship-country-autocomplete',
        'class' => ['form-label'],
      ],
      '#value' => 'Internship City: <span class="text-danger">*</span>',
    ];

    $form['internship_details']['body']['row']['fields_col']['country']['autocomplete_container'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#attributes' => [
        'id' => 'internship-country-container',
        'class' => ['autocomplete-container'],
      ],
      '#attached' => [
        'library' => ['lehigh_iiip/google_places_autocomplete'],
      ],
    ];

    // Hidden field to store the actual value for form submission.
    $form['internship_details']['body']['row']['fields_col']['country']['latitude_longitude'] = [
      '#type' => 'hidden',
      '#attributes' => [
        'id' => 'internship-country-value',
      ],
      '#required' => TRUE,
    ];

    $form['internship_details']['body']['company_description'] = [
      '#type' => 'textarea',
      '#title' => 'Company and Position Description:',
      '#attributes' => [
        'class' => ['form-control', 'mb-4'],
        'rows' => 4,
      ],
      '#required' => $required,
    ];

    $form['internship_details']['body']['value_derived'] = [
      '#type' => 'textarea',
      '#title' => 'Value Derived from Experience:',
      '#attributes' => [
        'class' => ['form-control'],
        'rows' => 4,
      ],
      '#required' => $required,
    ];

    $form['file_uploads'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#attributes' => [
        'class' => ['card', 'mb-4'],
      ],
    ];

    $form['file_uploads']['header'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#attributes' => [
        'class' => ['card-header', 'bg-light'],
      ],
    ];

    $form['file_uploads']['header']['title'] = [
      '#type' => 'html_tag',
      '#tag' => 'h3',
      '#attributes' => [
        'class' => ['text-dark'],
      ],
      '#value' => 'File Uploads',
    ];

    $form['file_uploads']['body'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#attributes' => [
        'class' => ['card-body'],
      ],
    ];

    $extensions .= ' mp4 mov avi pdf';
    for ($i = 0; $i < 4; $i++) {
      $form['file_uploads']['body']["upload_row_$i"] = [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#attributes' => [
          'class' => ['row', 'mb-3'],
        ],
      ];

      $form['file_uploads']['body']["upload_row_$i"]['upload_col'] = [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#attributes' => [
          'class' => ['col-md-3', 'text-center'],
        ],
      ];

      $form['file_uploads']['body']["upload_row_$i"]['upload_col']["file_upload_$i"] = [
        '#type' => 'managed_file',
        '#title' => '',
        '#description' => $extensions,
        '#upload_location' => 'fedora://iiip/submissions/' . $this->currentUser->id(),
        '#upload_validators' => [
          'FileExtension' => ['extensions' => $extensions],
        ],
        '#attributes' => [
          'class' => ['btn', 'btn-outline-primary', 'btn-sm', 'w-100'],
        ],
      ];

      $form['file_uploads']['body']["upload_row_$i"]['title_col'] = [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#attributes' => [
          'class' => ['col-md-4'],
        ],
      ];

      $form['file_uploads']['body']["upload_row_$i"]['title_col']["title_$i"] = [
        '#type' => 'textfield',
        '#title' => 'Title of file',
        '#title_display' => 'invisible',
        '#attributes' => [
          'class' => ['form-control', 'form-control-sm'],
          'placeholder' => 'Title of file',
        ],
      ];

      $form['file_uploads']['body']["upload_row_$i"]['desc_col'] = [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#attributes' => [
          'class' => ['col-md-5'],
        ],
      ];

      $form['file_uploads']['body']["upload_row_$i"]['desc_col']["description_$i"] = [
        '#type' => 'textarea',
        '#title' => 'Description of file',
        '#title_display' => 'invisible',
        '#attributes' => [
          'class' => ['form-control', 'form-control-sm'],
          'placeholder' => 'Description of file',
        ],
      ];
    }

    $form['file_uploads']['body']['permissions'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#attributes' => [
        'class' => ['mt-4'],
      ],
    ];

    $form['file_uploads']['body']['permissions']['permission_use'] = [
      '#type' => 'checkbox',
      '#title' => 'I certify that I have permission to use the attached images.',
      '#attributes' => [
        'class' => ['form-check-input'],
      ],
      '#required' => $required,
    ];

    $form['file_uploads']['body']['permissions']['understand_public'] = [
      '#type' => 'checkbox',
      '#title' => 'I understand that all submitted text and imagery will be made publically available online.',
      '#attributes' => [
        'class' => ['form-check-input', 'mt-2'],
      ],
      '#required' => $required,
    ];

    $form['actions'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#attributes' => [
        'class' => ['text-center', 'mt-4'],
      ],
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => 'Submit',
      '#attributes' => [
        'class' => ['btn', 'btn-warning', 'btn-lg', 'px-5'],
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $values = $form_state->getValues();
    $has_files = FALSE;
    for ($i = 0; $i < 4; $i++) {
      if (!empty($values["file_upload_$i"])) {
        $has_files = TRUE;
        if (empty($values["title_$i"])) {
          $form_state->setErrorByName("title_$i", $this->t('You must provide a title for each file.'));
        }
        if (empty($values["description_$i"])) {
          $form_state->setErrorByName("description_$i", $this->t('You must provide a description for each file.'));
        }
      }
    }

    if (!$has_files) {
      $form_state->setErrorByName('file_uploads', $this->t('Please upload at least one file.'));
    }

    if (empty($values['student_photo'])) {
      $form_state->setErrorByName('student_photo', $this->t('Please upload your photo.'));
    }

    $coordinates = explode(',', $values['latitude_longitude']);
    if (count($coordinates) !== 2 || !is_numeric($coordinates[0]) || !is_numeric($coordinates[1])) {
      $form_state->setErrorByName('latitude_longitude',
        $this->t('Please select the city your internship was conducted in.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $values = $form_state->getValues();
    $uid = $this->currentUser->id();
    $coordinates = explode(',', $values['latitude_longitude']);
    $person = lehigh_islandora_get_tid_by_name($values['student_name'] . ' - IIIP', 'person', TRUE);

    $student = Node::create([
      'type' => 'islandora_object',
      'field_member_of' => 453222,
      'field_model' => lehigh_islandora_get_tid_by_name('Compound Object', 'islandora_models'),
      'title' => substr($values['student_name'], 0, 255),
      'field_full_title' => $values['student_name'],
      'field_degree_name' => lehigh_islandora_get_tid_by_name($values['student_major'], 'degree_name', TRUE),
      'field_genre' => lehigh_islandora_get_tid_by_name('summaries', 'genre'),
      'field_resource_type' => lehigh_islandora_get_tid_by_name('Text', 'resource_types'),
      'field_edtf_date_issued' => $values['internship_year'],
      'field_affiliated_institution' => lehigh_islandora_get_tid_by_name($values['company'], 'corporate_body', TRUE),
      'field_linked_agent' => [
        'rel_type' => 'relators:cre',
        'target_id' => $person,
      ],
      'field_coordinates' => [
        'lat' => $coordinates[0],
        'lng' => $coordinates[1],
      ],
      'uid' => $uid,
      'status' => 0,
      'field_abstract' => [
        [
          'attr0' => 'description',
          'format' => 'basic_html',
          'value' => '<p><strong>Company and Position Description:</strong></p>' . $values['company_description'],
        ],
        [
          'attr0' => 'description',
          'format' => 'basic_html',
          'value' => '<p></p><p><strong>Value Derived from Experience:</strong></p>' . $values['value_derived'],
        ],
      ],
    ]);
    $student->save();
    $nid = $student->id();

    /** @var \Drupal\file\FileStorageInterface $file_storage */
    $file_storage = $this->entityTypeManager->getStorage('file');

    $fid = $values['student_photo'];
    if (!empty($fid[0])) {
      $file = $file_storage->load($fid[0]);
      if ($file) {
        $file->setPermanent();
        $file->save();
        $selfie = Media::create([
          'name' => "$nid - photo",
          'bundle' => 'image',
          'field_media_image' => $fid[0],
          'field_media_of' => $nid,
          'field_media_use' => lehigh_islandora_get_tid_by_name('Original File', 'islandora_media_use'),
          'status' => 1,
        ]);
        $selfie->save();
      }
    }

    for ($i = 0; $i < 4; $i++) {
      if (empty($values["file_upload_$i"])) {
        continue;
      }
      $fid = $values["file_upload_$i"];
      if (empty($fid[0])) {
        continue;
      }
      $file = $file_storage->load($fid[0]);
      if (!$file) {
        continue;
      }
      $file->setPermanent();
      $file->save();
      $uri = $file->getFileUri();
      $extension = pathinfo($uri, PATHINFO_EXTENSION);
      $model = "Image";
      $field = 'field_media_image';
      $bundle = 'image';
      $resource_type = 'Still Image';
      switch ($extension) {
        case 'avi':
        case 'mp4':
        case 'mov':
          $field = 'field_media_video_file';
          $bundle = 'video';
          $model = 'Video';
          $resource_type = 'Moving Image';
          break;

        case 'pdf':
          $field = 'field_media_document';
          $bundle = 'document';
          $model = 'Digital Document';
          $resource_type = 'Text';
          break;
      }
      $node = Node::create([
        'type' => 'islandora_object',
        'field_member_of' => $student->id(),
        'field_model' => lehigh_islandora_get_tid_by_name($model, 'islandora_models'),
        'title' => substr($values["title_$i"], 0, 255),
        'field_full_title' => $values["title_$i"],
        'field_genre' => lehigh_islandora_get_tid_by_name('supplements (document genre)', 'genre'),
        'field_resource_type' => lehigh_islandora_get_tid_by_name($resource_type, 'resource_types'),
        'field_edtf_date_issued' => date('Y-m-d'),
        'uid' => $uid,
        'status' => 0,
        'field_linked_agent' => [
          'rel_type' => 'relators:cre',
          'target_id' => $person,
        ],
        'field_abstract' => [
          [
            'attr0' => 'description',
            'format' => 'basic_html',
            'value' => '<p>Company and Position Description:</p>'
            . '<p>' . $values['position_title'] . '</p>'
            . '<p>' . $values["description_$i"] . '</p>',
          ],
        ],
      ]);
      $node->save();
      $nid = $node->id();
      $media = Media::create([
        'name' => "$nid - photo",
        'bundle' => $bundle,
        $field => $fid[0],
        'field_media_of' => $nid,
        'field_media_use' => lehigh_islandora_get_tid_by_name('Original File', 'islandora_media_use'),
        'status' => 1,
      ]);
      $media->save();
    }

    // Save the values just in case as we roll this out.
    \Drupal::logger('islandora_iiip')->notice("Submission received @values", ['@values' => json_encode($values)]);
    $form_state->setRedirect('entity.node.canonical', ['node' => $student->id()]);
  }

}
