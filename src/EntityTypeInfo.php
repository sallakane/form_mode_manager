<?php

namespace Drupal\form_mode_manager;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityDisplayRepositoryInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Manipulates entity type information.
 *
 * This class contains primarily bridged hooks for compile-time or
 * cache-clear-time hooks. Runtime hooks should be placed in EntityOperations.
 */
class EntityTypeInfo implements ContainerInjectionInterface {

  use StringTranslationTrait;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  /**
   * The entity display repository.
   *
   * @var \Drupal\Core\Entity\EntityDisplayRepositoryInterface
   */
  protected $entityDisplayRepository;

  /**
   * The entity display repository.
   *
   * @var \Drupal\form_mode_manager\FormModeManager
   */
  protected $formModeManager;

  /**
   * EntityTypeInfo constructor.
   *
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   Current user.
   * @param \Drupal\Core\Entity\EntityDisplayRepositoryInterface $entity_display_repository
   *   The entity display repository.
   * @param \Drupal\form_mode_manager\FormModeManagerInterface $form_mode_manager
   *   The form mode manager.
   */
  public function __construct(AccountInterface $current_user, EntityDisplayRepositoryInterface $entity_display_repository, FormModeManagerInterface $form_mode_manager) {
    $this->entityDisplayRepository = $entity_display_repository;
    $this->currentUser = $current_user;
    $this->formModeManager = $form_mode_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('current_user'),
      $container->get('entity_display.repository'),
      $container->get('form_mode.manager')
    );
  }

  /**
   * Adds FormModeManager forms/links to appropriate entity types.
   *
   * This is an alter hook bridge.
   *
   * @param \Drupal\Core\Entity\EntityTypeInterface[] $entity_types
   *   The master entity type list to alter.
   *
   * @see hook_entity_type_alter()
   */
  public function entityTypeAlter(array &$entity_types) {
    $available_entity_types = array_keys($this->formModeManager->getAllFormModesDefinitions());
    foreach ($available_entity_types as $entity_type_id) {
      /* @var \Drupal\Core\Entity\EntityTypeInterface $entity_definition */
      if ($entity_definition = $entity_types[$entity_type_id]) {
        $form_modes = $this->formModeManager->getFormModesIdByEntity($entity_type_id);
        if (($entity_definition->getFormClass('default') || $entity_definition->getFormClass('edit')) && $entity_definition->hasLinkTemplate('edit-form')) {
          $entity_definition->setLinkTemplate('form-modes-list', "/form-mode-manager/$entity_type_id/{{$entity_type_id}}");
        }
        foreach ($form_modes as $form_mode_name) {
          // Ajout du form handler basé sur la définition de base.
          if ($default_form = $entity_definition->getFormClass('default')) {
            $entity_definition->setFormClass($form_mode_name, $default_form);
          }
          // Ajout de l'opération d'entité dans le context "edit".
          if ($entity_definition->getFormClass($form_mode_name) && $entity_definition->hasLinkTemplate('edit-form')) {
            $entity_definition->setLinkTemplate("edit-form-$form_mode_name", $entity_definition->getLinkTemplate('edit-form') . '/{form_mode_name}');
          }
        }
      }
    }
  }

  /**
   * Adds FormModeManager operations on entity that supports it.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity on which to define an operation.
   *
   * @return array
   *   An array of operation definitions.
   *
   * @see hook_entity_operation()
   */
  public function entityOperation(EntityInterface $entity) {
    $operations = [];
    $entity_type_id = $entity->getEntityTypeId();
    $form_modes = $this->formModeManager->getFormModesByEntity($entity_type_id);
    $active_form_modes = $this->formModeManager->getActiveDisplaysByBundle($entity_type_id, $entity->bundle());
    if (isset($active_form_modes[$entity_type_id])
      && $active_modes = array_intersect_key($form_modes, $active_form_modes[$entity_type_id])
    ) {
      foreach ($active_modes as $form_mode_name => $form_mode) {
        if ($this->currentUser->hasPermission("use {$form_mode['id']} form mode") && $entity->hasLinkTemplate("edit-form-$form_mode_name")) {
          $operations += [
            $form_mode_name => [
              'title' => $this->t('Edit as @form_mode_name', ['@form_mode_name' => $form_mode['label']])->render(),
              'url' => $entity->toUrl("edit-form-$form_mode_name"),
              'weight' => 100,
            ],
          ];
        }
      }
    }

    return $operations;
  }

}