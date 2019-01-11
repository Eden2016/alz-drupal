<?php
namespace Drupal\zaboutme\Entity;

use Drupal\quick_node_clone\Entity;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Entity\EntityFormBuilder;
use Drupal\Core\Form\FormState;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityManagerInterface;

/**
 * Builds entity forms.
 */
class ZaboutmeQuickNodeCloneEntityFormBuilder extends QuickNodeCloneEntityFormBuilder {

  /**
   * {@inheritdoc}
   */
  public function getForm(EntityInterface $original_entity, $operation = 'default', array $form_state_additions = array()) {

    $original_entity->set('field_created_from', [2]);
    return parent::getForm($original_entity, $operation, $form_state_additions);
  }

}
