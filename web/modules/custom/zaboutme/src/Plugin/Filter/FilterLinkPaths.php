<?php

namespace Drupal\zaboutme\Plugin\Filter;

use Drupal\filter\FilterProcessResult;
use Drupal\filter\Plugin\FilterBase;
use Drupal\Core\Url;

/**
 * @Filter(
 *   id = "filter_link_paths",
 *   title = @Translation("Link Paths Filter"),
 *   description = @Translation("Process gorup links to Home site"),
 *   type = Drupal\filter\Plugin\FilterInterface::TYPE_MARKUP_LANGUAGE,
 * )
 */
class FilterLinkPaths extends FilterBase {
  
  private $currentPath;
  
  public function process($text, $langcode) {
    $this->currentPath = explode('/', Url::fromRoute('<current>')->toString());
    if (!empty($this->currentPath[2])) { $this->currentPath[2] = strtolower($this->currentPath[2]); }
    
    $text = preg_replace_callback('/(href="\/.{2})\/([^\/|"]*)\/?([^"]*)"/', 'self::parseLink', $text);
    //case where they linked to a file in the sitecore structure
    $text = str_replace('staging.alzheimer.ca/sitecore/shell/Controls/Rich%20Text%20Editor/', \Drupal::request()->getHost(), $text);
    //redirect from staging urls
    $text = str_replace('staging.alzheimer.ca', \Drupal::request()->getHost(), $text);
    $index = 0;
    $text = preg_replace_callback('/(<h1|<h2|<h3|<h4)/', function( $matches ) use ( &$index ) { 
        return $this->parseHeaders($matches, $index); 
    }, $text);
    return new FilterProcessResult($text);
  }
  
  /**
   * Convert links going to "Home/" so they link to the local society page instead if exists. This is a result of cloning.
   * 
   * @param type $matches
   * @return type
   */
  private function parseLink($matches)
  {
    $path = $this->currentPath[2] == strtolower($matches[2]) || empty($matches[3]) ? $matches[0] : implode("/", [$matches[1], $this->currentPath[2], $matches[3]]) . '"';
    //$url = \Drupal::service('path.validator')->isValid($path) ? 'href="' . $path . '"' : $matches[0];
    return $path;
  }
  
  /**
   * Add id properties to h2 and h3 tags
   * 
   * @param type $matches
   * @param int $index
   * @return string
   */
  private function parseHeaders($matches, &$index)
  {
    $index++;
    return $matches[1] . ' id="DynamicId_' . $index . '" ';
  }
}