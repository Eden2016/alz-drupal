<?php

/**
 * @file
 * Contains \Drupal\zaboutme\Plugin\Field\FieldFormatter\LinkPathFormatter.
 */

namespace Drupal\zaboutme\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\link\Plugin\Field\FieldFormatter\LinkFormatter;
use Drupal\Core\Url;

/**
* Plugin implementation of the 'link_path' formatter.
*
* @FieldFormatter(
*   id = "link_path",
*   label = @Translation("Site aware link path"),
*   field_types = {
*     "link"
*   }
* )
*/
class LinkPathFormatter extends LinkFormatter {
  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    $elements = parent::viewElements($items, $langcode);
    if (!empty($elements[0]['#url']))
    {
      $cp = explode('/', Url::fromRoute('<current>')->toString());

      $parts = explode('/', $elements[0]['#url']->toString());
      if (strtolower($parts[2]) == 'home')
      {
        $parts[2] = $cp[2];
        $path = implode('/' , $parts);
        if (\Drupal::service('path.validator')->isValid($path))
        {
          $elements[0]['#url'] = Url::fromUri('internal:' . $path);
        }
      }
    }

    return $elements;
  }
}
