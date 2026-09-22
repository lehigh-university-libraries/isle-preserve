<?php

namespace Drupal\lehigh_analytics\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\lehigh_analytics\UsageReport;
use Drupal\lehigh_analytics\ReportDisplay;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Staff reporting page with shared metadata filters and CSV exports.
 */
final class ReportForm extends FormBase {

  public function __construct(
    private readonly UsageReport $reports,
    private readonly ReportDisplay $display,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static($container->get('lehigh_analytics.reports'), $container->get('lehigh_analytics.display'));
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'lehigh_analytics_report';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $criteria = $this->reports->criteria($this->getRequest()->query->all() + ['period' => 'fiscal']);
    $view = $this->getRequest()->query->get('report_view', 'types');
    $options = UsageReport::TITLES;
    unset($options['counter'], $options['acrl']);
    if (!isset($options[$view])) {
      throw new BadRequestHttpException('Invalid report selection.');
    }
    $form['#cache']['max-age'] = 0;
    $form['report_view'] = [
      '#type' => 'select',
      '#title' => $this->t('Report'),
      '#options' => $options,
      '#default_value' => $view,
    ];
    $form += $this->display->controls($criteria);
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = ['#type' => 'submit', '#value' => $this->t('Run report')];
    $form['actions']['reset'] = Link::createFromRoute($this->t('Reset filters'), 'lehigh_analytics.review')->toRenderable();

    $form['definitions'] = [
      '#type' => 'details',
      '#title' => $this->t('Data definitions and limitations'),
      'text' => ['#markup' => $this->t('<p>Source: Entity Metrics. Page views are recorded node visits. Downloads are recorded media file requests, attributed through Media of; they are not necessarily unique or completed downloads. Only currently published Islandora objects are included. Records flagged with the staff cookie are excluded. Deleted works and unlinked media cannot be attributed.</p><p>Top documents exclude Collection and Page models; each ranking uses its own metric, with node ID breaking ties. Usage on component pages is not rolled up to parent works. Multivalued grouping fields can place the same event in more than one content type; use the Recorded usage summary for unduplicated totals.</p><p>These are recorded events, not unique people, verified human traffic, or COUNTER-compliant usage. Bot traffic and historical tracking gaps may affect counts. Missing geography stays Unknown. Google Analytics has different collection and exclusion rules; do not add its numbers to these totals.</p>')],
    ];
    if ($this->getRequest()->isMethod('GET') && $this->getRequest()->query->has('report_view')) {
      $form[$view] = [
        '#type' => 'details',
        '#title' => UsageReport::TITLES[$view],
        '#open' => TRUE,
      ] + $this->display->build($view, $criteria);
    }
    else {
      $form['instructions'] = ['#plain_text' => $this->t('Choose a report, period, and optional metadata filters, then select Run report.')];
    }
    $form['standards'] = $this->display->downloads($criteria);
    $form['country_source']['source_note'] = [
      '#markup' => $this->t('<p>Country codes come from saved Entity Metrics regions. Unknown includes events without a saved country. Local geography may be incomplete. Use the Google Analytics country report for global reach: select “LU Islandora Digital Collections - GA4”, open Reports → User attributes → Demographic details, choose Country, set the dates shown above, and sort by the metric you report. Label GA user counts as users, not page views or downloads. For all time, use the earliest available GA date.</p>'),
    ];
    $ga_url = $this->config('lehigh_analytics.settings')->get('google_analytics_url');
    // Configuration can point to a saved report, but never an arbitrary scheme.
    if (is_string($ga_url) && preg_match('#^https://analytics\.google\.com/#', $ga_url)) {
      $form['country_source']['google_analytics'] = Link::fromTextAndUrl($this->t('Open Google Analytics'), Url::fromUri($ga_url))->toRenderable();
    }
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $criteria = $this->display->submitted($form_state->getValues());
    $criteria['report_view'] = $form_state->getValue('report_view');
    $form_state->setRedirect('lehigh_analytics.review', [], ['query' => $criteria]);
  }

}
