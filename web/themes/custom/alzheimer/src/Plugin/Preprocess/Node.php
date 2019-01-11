<?php
/**
 * @file
 * Contains \Drupal\alzheimer\Plugin\Preprocess\Node.
 */

namespace Drupal\alzheimer\Plugin\Preprocess;

use Drupal\bootstrap\Plugin\Preprocess\PreprocessBase;
use Drupal\bootstrap\Plugin\Preprocess\PreprocessInterface;
use Drupal\Core\Url;
use Drupal\Core\Link;

//ref: https://drupal-bootstrap.org/api/bootstrap/docs%21plugins%21Preprocess.md/group/plugins_preprocess/8

/**
 * Pre-processes variables for the "node" theme hook.
 *
 * @ingroup plugins_preprocess
 *
 * @BootstrapPreprocess("node")
 */
class Node extends PreprocessBase implements PreprocessInterface {

  /**
   * {@inheritdoc}
   */
  public function preprocess(array &$variables, $hook, array $info) {
    switch ($variables['node']->getType())
    {
      case 'news':
      case 'event':
        if (!$variables['node']->field_link->isEmpty()) { 
          $variables['node']->field_link->first()->set('title', $variables['node']->body->value);
          $settings = [];
          if ($variables['node']->field_link->first()->isExternal()) {
            $settings = ['target' => '_blank'];
          }
          $variables['display_link'] = $variables['node']->field_link->view(['label' => 'hidden', 'settings' => $settings]);
        }
        elseif (!$variables['node']->field_file_link->isEmpty()) { 
          if (!empty($variables['node']->field_file_link->entity->field_document->entity->uri->value)) { $url = file_create_url($variables['node']->field_file_link->entity->field_document->entity->uri->value); }
          elseif (!empty($variables['node']->field_file_link->entity->field_image->entity->uri->value)) { $url = file_create_url($variables['node']->field_file_link->entity->field_image->entity->uri->value); }
          
          if (!empty($url))
          {
            $url = Url::fromUri($url);
            $url->setOptions(['attributes' => ['target' => '_blank']]);
            $variables['display_link'] = Link::fromTextAndUrl($variables['node']->body->value ?: $variables['node']->getTitle(), $url );
          }
        }
        break;
      case 'article':
        $meta = unserialize($variables['node']->field_meta_tags->value);
        $variables['meta_title']  = $meta['title'];
        break;
      case 'landing_page':
        if (empty($variables['content']['field_events']['#items']))
        {
          $variables['content']['field_events_title'] = [];
        }
        if (empty($variables['content']['field_breaking_news']['#items']))
        {
          $variables['content']['field_breaking_news_title'] = [];
        }
        break;
      case 'content_module':
        if (!empty($variables['content']['field_link'][0]))
        {
          $path = explode('/', Url::fromRoute('<current>')->toString());
          $parts = explode('/', $variables['content']['field_link'][0]['#title']);
          if ($variables['content']['field_link'][0]['#url']->isRouted())
          {
            
            if (strtolower($path[2]) != strtolower($parts[2]))
            {
              $parts[2] = $path[2];
              $variables['link_url'] = implode('/', $parts);
            }
            else {
              $variables['link_url'] = $variables['content']['field_link'][0]['#title'];
            }
          }
          else
          {
            $variables['link_url'] = $variables['content']['field_link'][0]['#url']->getUri();
          }
        }
        break;
    }
       
    parent::preprocess($variables, $hook, $info);
  }

}
