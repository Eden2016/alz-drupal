<?php
/**
 * @file
 * Contains \Drupal\alzheimer\Plugin\Preprocess\Html.
 */

namespace Drupal\alzheimer\Plugin\Preprocess;

use Drupal\bootstrap\Plugin\Preprocess\PreprocessBase;
use Drupal\bootstrap\Plugin\Preprocess\PreprocessInterface;
use Drupal\Core\Url;

//ref: https://drupal-bootstrap.org/api/bootstrap/docs%21plugins%21Preprocess.md/group/plugins_preprocess/8

/**
 * Pre-processes variables for the "html" theme hook.
 *
 * @ingroup plugins_preprocess
 *
 * @BootstrapPreprocess("html")
 */
class Html extends PreprocessBase implements PreprocessInterface {

  /**
   * {@inheritdoc}
   */
  public function preprocess(array &$variables, $hook, array $info) {
    $lang_code = \Drupal::languageManager()->getCurrentLanguage()->getId();
    $variables['page']['#group'] = \Drupal::routeMatch()->getParameter('group');
    
    if (!empty($variables['page']['#group']))
    {
      //get the proper language data
      if ($variables['page']['#group']->hasTranslation($lang_code)) { $variables['page']['#group'] = $variables['page']['#group']->getTranslation($lang_code); }
      $meta = unserialize($variables['page']['#group']->field_meta_tags->value);

      $variables['head_title']['title'] = empty($meta['title']) ? t('Home') : $meta['title'];
      $variables['page']['#is_group'] = TRUE;
    }
    else
    {
      $node = \Drupal::routeMatch()->getParameter('node');

      if (!empty($node))
      {
        if (!empty($node->field_head_script))
        {
          $variables['head_script'] = $node->field_head_script->value;
        }  
        
        if (!empty($node->field_article_layout) && $node->field_article_layout->value == 3)
        {
          $variables['attributes']['class'][] = 'full-width';
        }

        //first try to get the group from the relationship
        $group = \Drupal::entityTypeManager()->getStorage('group_content')->loadByEntity($node);
        if (!empty($group))
        {
          $group = reset($group);
          $variables['page']['#group'] = $group->getGroup();
        }
        else 
        {
          //get group
          $router = \Drupal::service('router.no_access_checks');
          $path = explode('/', Url::fromRoute('<current>')->toString());

          try
          {
            $p = '/' . $path[2];
            if (substr($path[2], 0, 7) == 'chapter') { 
              $p .= '/' . $path[3]; 
            }

            $result = $router->match($p);
            if (!empty($result['group']))
            {
              $variables['page']['#group'] = $result['group'];
            }

          }
          catch(\Symfony\Component\Routing\Exception\ResourceNotFoundException $ex)
          {
          }
        }
        
        if (!empty($variables['page']['#group']) && $variables['page']['#group']->hasTranslation($lang_code)) { $variables['page']['#group'] = $variables['page']['#group']->getTranslation($lang_code); }
      }
    }
    
    if (empty($variables['page']['#group'])) {  //default to Home
      $router = \Drupal::service('router.no_access_checks');
      $result = $router->match('/Home');
      if (!empty($result['group']))
      {
        $variables['page']['#group'] = $result['group'];
      }
    }
    $variables['head_title']['name'] = $variables['page']['#group']->field_society_suffix->value;
    
    parent::preprocess($variables, $hook, $info);
  }

}
