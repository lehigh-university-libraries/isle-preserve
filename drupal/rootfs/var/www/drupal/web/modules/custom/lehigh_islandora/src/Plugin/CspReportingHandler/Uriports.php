<?php

namespace Drupal\lehigh_islandora\Plugin\CspReportingHandler;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\csp\Csp;
use Drupal\csp\Plugin\ReportingHandlerBase;

/**
 * CSP Reporting Plugin for uriports service.
 *
 * @CspReportingHandler(
 *   id = "uriports-com",
 *   label = "uriports",
 *   description = @Translation("Reports will be sent to a uriports.com account."),
 * )
 *
 * @see uriports.com
 */
class Uriports extends ReportingHandlerBase {

  use StringTranslationTrait;

  /**
   * {@inheritdoc}
   */
  public function getForm(array $form) {

    $form['subdomain'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Subdomain'),
      '#description' => $this->t(
          'Your <a href=":url">uriports.com subdomain</a>.', [
            ':url' => 'https://www.uriports.com/',
          ]
      ),
      '#default_value' => $this->configuration['subdomain'] ?? '',
      '#states' => [
        'required' => [
          ':input[name="' . $this->configuration['type'] . '[enable]"]' => ['checked' => TRUE],
          ':input[name="' . $this->configuration['type'] . '[reporting][handler]"]' => ['value' => $this->pluginId],
        ],
      ],
    ];

    unset($form['#description']);

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $subdomain = $form_state->getValue($form['subdomain']['#parents']);
    // Custom domains must be 4-30 characters, but generated domains are 32.
    if (!preg_match('/^[a-z\d]{4,32}$/i', $subdomain)) {
      $form_state->setError($form['subdomain'], 'Must be 4-30 alphanumeric characters.');
    }
  }

  /**
   * {@inheritdoc}
   */
  public function alterPolicy(Csp $policy) {
    $type = $this->configuration['type'] == 'report-only' ? 'report' : 'enforce';

    $policy->setDirective(
          'report-uri',
          'https://' . $this->configuration['subdomain'] . '.uriports.com/reports/' . $type
      );
  }

}
