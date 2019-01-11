<?php

namespace Drupal\zaboutme\Controller;

/**
 * Controller routines for update routes.
 */
class ZaboutmeSitemapController extends \Drupal\sitemap\Controller\SitemapController {
  public function buildPage($group_name) {
    $sitemap = array(
      '#theme' => 'sitemap',
      'variables' => [
        "tyty" => "tyty", //try four
      ],
    );

    return $sitemap;
  }
}
