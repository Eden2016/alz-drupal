<?php

namespace Drupal\zaboutme\Plugin\Block;

use Drupal\Core\Block\BlockBase;

/**
 * Provides a 'OnThisPage' Block.
 *
 * @Block(
 *   id = "zaboutme_onthispage",
 *   class = "sidebar-item sidebar-nav",
 *   admin_label = @Translation("On This Page block"),
 *   category = @Translation("Alzheimer Block"),
 * )
 */
class ZaboutmeOnThisPageBlock extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function build() {
    $node = \Drupal::routeMatch()->getParameter('node');
    
    if (empty($node->field_article_layout) || $node->field_article_layout->value != 1)
    {
      return NULL;
    }
    preg_match_all('/(<h1|<h2|<h3|<h4)([^>]*)>.*/', $node->body->value, $matches);

    $links = [];
    for ($i = 0, $c = count($matches[0]); $i < $c; $i++)
    { 
      $text = strip_tags($matches[0][$i]);
      if (!empty($text) && $text != '&nbsp;') { $links[] = '<li><a href="#DynamicId_' . ($i+1) . '">' . $text . '</a></li>'; }      
    }

    return empty($links) ? NULL : array(
      '#markup' => '<h3>' . t('On this page') . '</h3><ul>' . implode('', $links) . '</ul>',
      '#prefix' => '<div class="sidebar-item sidebar-nav">',
      '#suffix' => '</div>',
      '#cache' => ['contexts' => ['url.path']]
    );
  }
  
  public function preRender() {
  }

}