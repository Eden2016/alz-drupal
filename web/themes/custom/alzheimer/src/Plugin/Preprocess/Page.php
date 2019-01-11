<?php
/**
 * @file
 * Contains \Drupal\alzheimer\Plugin\Preprocess\Page.
 */

namespace Drupal\alzheimer\Plugin\Preprocess;

use Drupal\bootstrap\Annotation\BootstrapPreprocess;
use Drupal\bootstrap\Utility\Element;
use Drupal\bootstrap\Utility\Variables;
use Drupal\Core\Url;
use Drupal\Core\Link;

//ref: https://drupal-bootstrap.org/api/bootstrap/docs%21plugins%21Preprocess.md/group/plugins_preprocess/8

/**
 * Pre-processes variables for the "page" theme hook.
 *
 * @ingroup plugins_preprocess
 *
 * @BootstrapPreprocess("page")
 */
class Page extends \Drupal\bootstrap\Plugin\Preprocess\Page {

  /**
   * {@inheritdoc}
   */
  public function preprocess(array &$variables, $hook, array $info) {
    //language
    $lang_code = \Drupal::languageManager()->getCurrentLanguage()->getId();
    $lang_link = \Drupal::languageManager()->getLanguage($lang_code == 'en' ? 'fr' : 'en');
    $path = \Drupal::service('path.current')->getPath();
    $url = Url::fromUri('internal:' . $path, array ('language' => $lang_link));
    $link = Link::fromTextAndUrl($lang_link->getName(), $url);
    $link = $link->toRenderable();
    $link['#attributes'] = array('id' => 'globalmasthead_0_hlAltLang');
    $variables['lang_link'] = $link;
    $variables['language'] = $lang_code;
    $variables['title'] = $variables['page']['#title'];
    $variables['is_group'] = !empty($variables['page']['#is_group']);
    $do_hotspot = $variables['is_group']; //default to true for group and false for content
    $do_donate = $variables['is_group']; //default to true for group and false for content
    $donate_region = 'sidebar_second'; //articles with no right rail need the button in highlight
    $show_left_menu = FALSE;

    //setup node fields
    if (!empty($variables['node']))
    {
      switch($variables['node']->getType())
      {
        case 'article':
          switch($variables['node']->field_article_layout->value)
          {
            case '1':
              $show_left_menu = TRUE;
              $do_hotspot = !$variables['node']->field_hide_hotspots->value;
              $do_donate = TRUE;
              break;
            case '2':
              $do_donate = $variables['node']->field_include_donate->value;
              break;
            case '3':
              $do_donate = TRUE;
              $show_left_menu = TRUE;
              $donate_region = 'highlighted';
              break;
          }
          break;
        case 'landing_page':
        case 'page':
          $do_hotspot = TRUE;
          $do_donate = TRUE;
      }
    }

    //setup group fields
    if (!empty($variables['page']['#group']))
    {
      $settings_no_label =  array(
        'label' => 'hidden',
      );
      
      $group = $variables['page']['#group']->getTranslation($lang_code);
      if (!$group->hasTranslation($lang_code == 'en' ? 'fr' : 'en')) { $variables['lang_link'] = NULL; }
      $variables['group'] = $group;
      $variables['quick_links_first'] = $group->field_quick_links_first->value;
      $variables['quick_links_second'] = $group->field_quick_links_second->value;
      $variables['quick_links_third'] = $group->field_quick_links_third->value;
      $group_name = strtolower($group->label());
      
      if ($do_donate)
      {
        $variables['page'][$donate_region]['donate'] = $group->field_donate_now_html->view($settings_no_label);
        $variables['page'][$donate_region]['donate']['#weight'] = -50;
        $variables['page'][$donate_region]['#sorted'] = FALSE;
      }

      //hotspots
      if ($do_hotspot)
      {
        if (!empty($variables['node']->field_hotspots) && $variables['node']->field_hotspots->count() > 0)
        {
          $view_builder = \Drupal::entityManager()->getViewBuilder('node');
          $variables['page']['sidebar_second']['hotspots'] = $view_builder->viewMultiple($variables['node']->field_hotspots->referencedEntities(), 'default');
        }
        elseif (!empty($group->field_hotspots) && $group->field_hotspots->count() > 0)
        {
          $view_builder = \Drupal::entityManager()->getViewBuilder('node');
          $variables['page']['sidebar_second']['hotspots'] = $view_builder->viewMultiple($group->field_hotspots->referencedEntities(), 'default');
        }
      }
      
      $nav = [];

      $alias = \Drupal::request()->getRequestUri();
      foreach ($group->field_top_navigation->referencedEntities() as $item)
      {
        if ($item->hasTranslation($lang_code)) {
          $item = $item->getTranslation($lang_code);
        }
        $nav[] = ['url' => $item->url(), 'title' => $item->getTitle(), 'class' => strpos($alias, $item->url()) === 0 ? 'on' : ''];
      }
      $variables['nav_items'] = $nav;
      
      if ($show_left_menu)
      {
        $menu_tree_service = \Drupal::service('menu.link_tree');
        
        $params = $menu_tree_service->getCurrentRouteMenuTreeParameters($group_name);

        if (!empty($params))
        {
          //$params->minDepth = 2;
          $keys = array_keys($params->activeTrail);
          if (count($keys) > 2)
          {
            //use the top level item as the root for the menu. The last item is empty which is the root of the menu.
            $params->root = $keys[count($keys) - 2];
            $tree = \Drupal::menuTree()->load($group_name, $params);
            $manipulators = [
              ['callable' => 'menu.default_tree_manipulators:checkNodeAccess'],
              ['callable' => 'menu.default_tree_manipulators:checkAccess'],
              ['callable' => 'menu.default_tree_manipulators:generateIndexAndSort'],
              
            ];
            $variables['page']['sidebar_first']['sidemenu'] = $menu_tree_service->build($menu_tree_service->transform($tree, $manipulators));
          }
        }
      }
      
      $path = $group->url();
      $variables['accessibility_link'] = $path . '/Accessibility';
      $variables['contact_link'] = $path . '/ContactUs';
      $variables['search_link'] = $path . '/Search';

      //In province link - this is UGLY!! SHould find a cleaner solution
      $in_link = ['federationquebecoise' => '/federationquebecoise/About-us/Societe%20Alzheimer%20regionale', 
        'qc' => '/federationquebecoise/About-us/Societe%20Alzheimer%20regionale', 'on' => '/on/postal-code'];
      $in_province = ['federationquebecoise' => 'Québec', 'qc' => 'Québec', 'on' => 'Ontario'];
      if (!empty($variables['page']['#is_group']) && !empty($in_link[$group_name]))
      {
        $variables['chapter_search'] = $lang_code . $in_link[$group_name];
        $variables['chapter_province'] = $in_province[$group_name];
      }
      elseif (strpos($path, '/on') == 3) {
        $variables['chapter_search'] = $lang_code . $in_link['on'];
        $variables['chapter_province'] = $in_province['on'];
      }
      elseif (strpos($path, '/federationquebecoise') == 3) {
        $variables['chapter_search'] = $lang_code . $in_link['federationquebecoise'];
        $variables['chapter_province'] = $in_province['federationquebecoise'];
      }
      elseif ($group->type->getValue()[0]['target_id'] == 'chapter') 
      {
        $p = empty($group->field_chapter_province) ? null : strtolower($group->field_chapter_province->value);
        //temp fix for existing chapters that don't have the chapter_profince field set
        if (!$p)
        {
          if (in_array($group->label->value, ['bassaintlaurent','estrie','gim','lanaudiere','granby','montreal','suroit','rivesud']))
          {
            $p = 'federationquebecoise';
          }
          else {
            $p = 'on';
          }
        }
        $variables['chapter_search'] = $lang_code . $in_link[$p];
        $variables['chapter_province'] = $in_province[$p];
      }
      
      if (!empty($variables['page']['sidebar_second'])) { $variables['page']['sidebar_second']['#sorted'] = FALSE; }
    }
    
    parent::preprocess($variables, $hook, $info);
    
  }
  
  /**
   * Return a renderer for a field
   * 
   * @param type $field
   * @param type $group_id
   * @param type $view_mode
   * @return type
   */
  private function renderField($field, $group_id, $view_mode = 'default')
  {
    $def = $field->getFieldDefinition();
    $formatter = \Drupal::service('plugin.manager.field.formatter')->getInstance(array(
      'field_definition' => $def,
      'view_mode' => $view_mode,
      'configuration' => array(
      )
    ));
    $formatter->prepareView(array($group_id => $field));
    $renderer = $formatter->view($field);
    return $renderer;
  }

}
