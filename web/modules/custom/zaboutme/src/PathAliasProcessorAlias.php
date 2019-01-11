<?php

namespace Drupal\zaboutme;

use Drupal\Component\Utility\Unicode;
use Drupal\Core\Config\ConfigFactory;
use Drupal\Core\Path\AliasManagerInterface;
use Drupal\Core\PathProcessor\PathProcessorAlias;
use Drupal\Core\Render\BubbleableMetadata;
use Symfony\Component\HttpFoundation\Request;

/**
 * Processes inbound and outbound path determining alias.
 */
class PathAliasProcessorAlias extends PathProcessorAlias {

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactory
   */
  protected $configFactory;

  /**
   * Constructs a Path alias processor.
   *
   * @param \Drupal\Core\Path\AliasManagerInterface $alias_manager
   *   The alias manager service.
   * @param \Drupal\Core\Config\ConfigFactory $config_factory
   *   The config factory service.
   */
  public function __construct(AliasManagerInterface $alias_manager, ConfigFactory $config_factory) {
    parent::__construct($alias_manager);
    $this->configFactory = $config_factory;
  }

  /**
   * {@inheritdoc}
   */
  public function processInbound($path, Request $request) {
    $path_to_process = ltrim($path, '/');

    $removed_elements = [];
    $path_elements = explode('/', $path_to_process);
    if (substr(end($path_elements), 0, 14) == 'googlesitemap-') {
      $path_elements[1] = 'googlesitemap';
    }
    foreach ($path_elements as $element) {
      $candidate_alias = '/' . implode('/', $path_elements);
      $source = $this->aliasManager->getPathByAlias($candidate_alias);

      if ($source != $candidate_alias) {
        // Change the order of the elements.
        krsort($removed_elements);
        $return_path = $source;
        if (!empty($removed_elements)) {
          $return_path .= '/' . implode('/', $removed_elements);
        }

        // Validate the path.
        // Injecting the service threw ServiceCircularReferenceException.
        if (\Drupal::service('path.validator')->isValid($return_path)) {
          return $return_path;
        }
      }
      // Remove the last element from the elements array to be able to add it
      // to the end of the found path.
      $removed_elements[] = array_pop($path_elements);
    }

    return $path;
  }
}
