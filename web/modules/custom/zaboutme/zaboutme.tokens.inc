<?php

use Drupal\Core\Render\BubbleableMetadata;
use Drupal\zaboutme\Entity\MediaBundle;

/**
 * Implements hook_token_info().
 */
function zaboutme_token_info() {
  $type = [
    'name' => t('Zaboutme'),
    'description' => t('Custom tokens from zaboutme module'),
  ];
  $tokens['directory'] = [
    'name' => t("Value of Directory field"),
    'description' => t('Take Directory from POST array'),
  ];
  return [
    'types' => ['zaboutme' => $type],
    'tokens' => ['zaboutme' => $tokens],
  ];
}

/**
 * Implements hook_tokens().
 */
function zaboutme_tokens($type, $tokens, array $data, array $options, BubbleableMetadata $bubbleable_metadata) {
  $replacements = [];
  foreach ($tokens as $name => $original) {
    switch ($name) {
      case 'directory':
        if (isset($_POST['field_directory']) && is_numeric($_POST['field_directory'])) {
          $tid = $_POST['field_directory'];
          $ancestors = \Drupal::service('entity_type.manager')->getStorage("taxonomy_term")->loadAllParents($tid);
          $list = [];
          foreach ($ancestors as $term) {
            $list[$term->id()] = $term->label();;
          }
          $replacements[$original] = implode('/', array_reverse($list));
        }
        break;
    }
  }
  return $replacements;
}
