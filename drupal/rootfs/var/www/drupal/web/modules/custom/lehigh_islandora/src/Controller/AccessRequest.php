<?php

namespace Drupal\lehigh_islandora\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Form\FormBuilderInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Access request form.
 */
class AccessRequest extends ControllerBase {

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The render service.
   *
   * @var \Drupal\Core\Render\RendererInterface
   */
  protected $renderer;

  /**
   * The form builder.
   *
   * @var \Drupal\Core\Form\FormBuilderInterface
   */
  protected $formBuilder;

  /**
   * {@inheritdoc}
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, RendererInterface $renderer, FormBuilderInterface $form_builder) {
    $this->entityTypeManager = $entity_type_manager;
    $this->renderer = $renderer;
    $this->formBuilder = $form_builder;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
          $container->get('entity_type.manager'),
          $container->get('renderer'),
          $container->get('form_builder')
      );
  }

  /**
   * Get access request form.
   */
  public function accessRequestForm() {
    $entity = $this->entityTypeManager->getStorage('contact_message')->create([
      'contact_form' => 'access_request',
    ]);
    $form = $this->entityTypeManager->getFormObject('contact_message', 'default')->setEntity($entity);
    $form_render_array = $this->formBuilder->getForm($form);
    $form_render_array['#action'] = '/contact/access_request';

    return new JsonResponse(['form' => $this->renderer->renderRoot($form_render_array)]);
  }

}
