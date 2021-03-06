<?php

namespace Drupal\form_mode_manager\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\form_mode_manager\FormModeManagerInterface;
use Drupal\user\UserStorageInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller for entity form mode support.
 *
 * @see \Drupal\form_mode_manager\Routing\RouteSubscriber
 * @see \Drupal\form_mode_manager\Plugin\Derivative\FormModeManagerLocalAction
 * @see \Drupal\form_mode_manager\Plugin\Derivative\FormModeManagerLocalTasks
 */
abstract class EntityFormModeBase extends ControllerBase implements ContainerInjectionInterface {

  /**
   * The date formatter service.
   *
   * @var \Drupal\Core\Datetime\DateFormatterInterface
   */
  protected $dateFormatter;

  /**
   * The renderer service.
   *
   * @var \Drupal\Core\Render\RendererInterface
   */
  protected $renderer;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $account;

  /**
   * The entity display repository.
   *
   * @var \Drupal\form_mode_manager\FormModeManagerInterface
   */
  protected $formModeManager;

  /**
   * Constructs a EntityFormModeController object.
   *
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   The renderer service.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The current user.
   * @param \Drupal\form_mode_manager\FormModeManagerInterface $form_mode_manager
   *   The form mode manager.
   */
  public function __construct(RendererInterface $renderer, AccountInterface $account, FormModeManagerInterface $form_mode_manager) {
    $this->renderer = $renderer;
    $this->account = $account;
    $this->formModeManager = $form_mode_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('renderer'),
      $container->get('current_user'),
      $container->get('form_mode.manager')
    );
  }

  /**
   * Displays add content links for available entity types.
   *
   * Redirects to entity/add/[bundle] if only one content type is available.
   *
   * @param \Drupal\Core\Entity\EntityTypeInterface $entity_type
   *   The entity type definition. Useful when a single class is,
   *   used for multiple, possibly dynamic entity types.
   * @param string $form_mode_name
   *   The operation name identifying the form variation (form_mode).
   *
   * @return array|\Symfony\Component\HttpFoundation\RedirectResponse
   *   A render array for a list of the entity types that can be added; however,
   *   if there is only one entity type defined for the site, the function
   *   will return a RedirectResponse to the entity add page for that one entity
   *   type.
   */
  public function addPage(EntityTypeInterface $entity_type, $form_mode_name) {
    $entity_type_name = $entity_type->getBundleEntityType();
    $entity_type_id = $entity_type->id();
    $entity_type_cache_tags = $this->entityTypeManager()
      ->getDefinition($entity_type_name)
      ->getListCacheTags();
    $entity_type_definitions = $this->entityTypeManager()
      ->getStorage($entity_type_name)
      ->loadMultiple();

    $build = [
      '#theme' => 'form_mode_manager_add_list',
      '#cache' => [
        'tags' => Cache::mergeTags($entity_type_cache_tags, $this->formModeManager->getListCacheTags()),
      ],
    ];

    $content = [];
    foreach ($entity_type_definitions as $bundle) {
      $bundle_id = $bundle->id();
      $access = $this->entityTypeManager()
        ->getAccessControlHandler($entity_type_id)
        ->createAccess($bundle_id, $this->currentUser(), [], TRUE);

      if ($access->isAllowed() && $this->formModeManager->isActive($entity_type_id, $bundle_id, $form_mode_name)) {
        $content[$bundle_id] = $bundle;
        $this->renderer->addCacheableDependency($build, $access);
      }
    }

    // Bypass the entity/add listing if only one content type is available.
    if (1 == count($content)) {
      $bundle = array_shift($content);
      return $this->redirect("entity.$entity_type_id.add_form.$form_mode_name", [
        $entity_type_name => $bundle->id(),
      ]);
    }

    $build['#content'] = $content;
    $build['#form_mode'] = $form_mode_name;

    return $build;
  }

  /**
   * Provides the node submission form.
   *
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The current route match.
   *
   * @return array
   *   A node submission form.
   *
   * @throws \Exception
   */
  public function entityAdd(RouteMatchInterface $route_match) {
    /* @var \Drupal\Core\Entity\EntityInterface $entity */
    $entity = $this->getEntityFromRouteMatch($route_match);
    if (empty($entity)) {
      $route_entity_type_info = $this->getEntityTypeFromRouteMatch($route_match);
      /* @var \Drupal\Core\Entity\EntityInterface $entity */
      $entity = $this->entityTypeManager()
        ->getStorage($route_entity_type_info['entity_type_id'])
        ->create([
          $route_entity_type_info['entity_key'] => $route_entity_type_info['bundle'],
        ]);
    }

    $form_mode_id = $this->formModeManager->getFormModeMachineName($route_match->getRouteObject()
      ->getDefault('_entity_form'));
    $operation = empty($form_mode_id) ? 'default' : $form_mode_id;
    if ($entity instanceof EntityInterface) {
      return $this->entityFormBuilder()->getForm($entity, $operation);
    }

    throw new \Exception('Invalide entity passed or inexistant form mode');
  }

  /**
   * The _title_callback for the entity.add routes.
   *
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The current route match.
   *
   * @return string
   *   The page title.
   */
  public function addPageTitle(RouteMatchInterface $route_match) {
    $entity_storage = $this->getEntityBundle($route_match);
    $form_mode_label = $route_match->getRouteObject()
      ->getOption('parameters')['form_mode']['label'];
    return $this->t('Create @name as @form_mode_label', [
      '@name' => (!$entity_storage instanceof UserStorageInterface) ? $entity_storage->get('name') : $entity_storage->getEntityType()
        ->id(),
      '@form_mode_label' => $form_mode_label,
    ]);
  }

  /**
   * Get EntityStorage of entity.
   *
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The current route match.
   *
   * @return \Drupal\Core\Entity\EntityStorageInterface|\Drupal\Core\Entity\EntityInterface
   *   The storage of current entity or EntityInterface.
   */
  private function getEntityBundle(RouteMatchInterface $route_match) {
    /* @var \Drupal\Core\Entity\EntityInterface $entity */
    $entity = $this->getEntityFromRouteMatch($route_match);
    if (empty($entity)) {
      $route_entity_type_info = $this->getEntityTypeFromRouteMatch($route_match);
      /* @var \Drupal\Core\Entity\EntityTypeInterface $bundle */
      $bundle = $this->entityTypeManager()
        ->getStorage($route_entity_type_info['bundle_entity_type'])
        ->load($route_entity_type_info['bundle']);
    }
    else {
      /* @var \Drupal\Core\Entity\EntityTypeInterface $bundle */
      $bundle = $this->entityTypeManager()
        ->getStorage($route_match->getRouteObject()
          ->getOption('_form_mode_manager_bundle_entity_type_id'))
        ->load($entity->bundle());
    }

    if (empty($bundle)) {
      /* @var \Drupal\Core\Entity\EntityStorageInterface $bundle */
      $bundle = $this->entityTypeManager()
        ->getStorage($route_match->getRouteObject()
          ->getOption('_form_mode_manager_bundle_entity_type_id'));
    }

    return $bundle;
  }

  /**
   * Checks access for the Form Mode Manager routes.
   *
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The current route match.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  public function checkAccess(RouteMatchInterface $route_match) {
    /* @var \Drupal\Core\Entity\EntityInterface $entity */
    $entity = $this->getEntityFromRouteMatch($route_match);
    $route = $route_match->getRouteObject();
    $form_mode_id = $route->getDefault('_entity_form');
    $cache_tags = $this->formModeManager->getListCacheTags();

    if (empty($entity)) {
      $route_entity_type_info = $this->getEntityTypeFromRouteMatch($route_match);
      $entity_type_id = $route_entity_type_info['entity_type_id'];
      $bundle_id = isset($route_entity_type_info['bundle']) ? $route_entity_type_info['bundle'] : $route->getOption('_form_mode_manager_bundle_entity_type_id');
    }
    else {
      $entity_type_id = $route->getOption('_form_mode_manager_entity_type_id');
      $bundle_id = !empty($route_match->getParameter($entity_type_id)) ? $route_match->getParameter($entity_type_id)->bundle() : 'user';
    }

    return AccessResult::allowedIf($this->formModeManager->isActive($entity_type_id, $bundle_id, $this->formModeManager->getFormModeMachineName($form_mode_id)))->addCacheTags($cache_tags);
  }

  /**
   * Retrieves entity from route match.
   *
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The route match.
   *
   * @return \Drupal\Core\Entity\EntityInterface|null
   *   The entity object as determined from the passed-in route match.
   */
  protected function getEntityFromRouteMatch(RouteMatchInterface $route_match) {
    $parameter_name = $route_match->getRouteObject()
      ->getOption('_form_mode_manager_entity_type_id');
    $entity = $route_match->getParameter($parameter_name);
    return $entity;
  }

  /**
   * Retrieves entity from route match.
   *
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The route match.
   *
   * @return array
   *   The entity object as determined from the passed-in route match.
   */
  protected function getEntityTypeFromRouteMatch(RouteMatchInterface $route_match) {
    $route = $route_match->getRouteObject();
    $entity_type_id = $route->getOption('_form_mode_manager_entity_type_id');
    $bundle_entity_type_id = $route->getOption('_form_mode_manager_bundle_entity_type_id');
    $form_mode = $this->formModeManager->getFormModeMachineName($route->getDefault('_entity_form'));
    $bundle = $route_match->getParameter($bundle_entity_type_id);
    $form_mode_definition = $this->formModeManager->getActiveDisplaysByBundle($entity_type_id, $bundle);
    $entity_type_key = $this->entityTypeManager()
      ->getDefinition($entity_type_id)
      ->getKey('bundle');

    return [
      'bundle' => $bundle,
      'bundle_entity_type' => $bundle_entity_type_id,
      'entity_key' => $entity_type_key,
      'entity_type_id' => $entity_type_id,
      'form_mode' => !empty($form_mode_definition) ? $form_mode_definition[$entity_type_id][$form_mode] : NULL,
    ];
  }

}
