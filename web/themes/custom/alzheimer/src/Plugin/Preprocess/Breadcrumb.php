<?php
/**
 * @file
 * Contains \Drupal\zaboutme\Plugin\Preprocess\Breadcrumb.
 */

namespace Drupal\alzheimer\Plugin\Preprocess;

use Drupal\bootstrap\Utility\Variables;
use Drupal\Core\Render\Markup;

/**
 * Pre-processes variables for the "breadcrumb" theme hook.
 *
 * @ingroup plugins_preprocess
 *
 * @BootstrapPreprocess("breadcrumb")
 */
class Breadcrumb extends \Drupal\bootstrap\Plugin\Preprocess\Breadcrumb {

  /**
   * {@inheritdoc}
   */
  public function preprocess(array &$variables, $hook, array $info) {
    if (!empty($variables['breadcrumb'][1]))
    {
      $variables['breadcrumb'][1]['text'] = t('Home');
    }
    
    parent::preprocess($variables, $hook, $info);
  }
  
  /**
   * {@inheritdoc}
   */
  public function preprocessVariables(Variables $variables) {
    
    
    foreach ($variables['breadcrumb'] as $k => $v)
    {
      $variables['breadcrumb'][$k]['text'] = Markup::create(strip_tags($variables['breadcrumb'][$k]['text'], '<sup>'));
    }
    
    parent::preprocessVariables($variables);
  }

}
