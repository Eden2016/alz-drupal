<?php

namespace Drupal\zaboutme\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\group\Entity\GroupContent;
use Drupal\group\Entity\GroupInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Cache\CacheableResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Drupal\simple_sitemap\Simplesitemap;



/**
 * Class GroupXmlSiteMap
 * @package Drupal\zaboutme\Controller
 */
class GroupXmlSiteMap extends ControllerBase {
  const XML_VERSION = '1.0';
  const ENCODING = 'UTF-8';
  const XMLNS = 'http://www.sitemaps.org/schemas/sitemap/0.9';
  const XMLNS_XHTML = 'http://www.w3.org/1999/xhtml';
  const GENERATED_BY = 'Generated by the Simple XML sitemap Drupal module: https://drupal.org/project/simple_sitemap.';
  const FIRST_CHUNK_INDEX = 1;
  /**
   * The sitemap generator.
   *
   * @var \Drupal\simple_sitemap\Simplesitemap
   */
  protected $generator;

  /**
   * SimplesitemapController constructor.
   *
   * @param \Drupal\simple_sitemap\Simplesitemap $generator
   *   The sitemap generator.
   */
  public function __construct(Simplesitemap $generator) {
    $this->generator = $generator;
  }

  /**
   * Returns the whole sitemap, a requested sitemap chunk, or the sitemap index file.
   *
   * @param int $chunk_id
   *   Optional ID of the sitemap chunk. If none provided, the first chunk or
   *   the sitemap index is fetched.
   *
   * @throws NotFoundHttpException
   *
   * @return object
   *   Returns an XML response.
   */
  public function getSitemap(GroupInterface $group) {
    $entities = $group->getContentEntities();
    $writer = new \XMLWriter();
    $writer->openMemory();
    $writer->setIndent(TRUE);
    $writer->startDocument(self::XML_VERSION, self::ENCODING);
    $writer->writeComment(self::GENERATED_BY);
    $writer->startElement('urlset');
    $writer->writeAttribute('xmlns', self::XMLNS);
    $writer->writeAttribute('xmlns:xhtml', self::XMLNS_XHTML);
    foreach ($entities as $entity) {
      $conf = $this->generator->getBundleSettings($entity->getEntityTypeId(), $entity->bundle());
      if ($conf) {
        $writer->startElement('url');
        $writer->writeElement('loc', $entity->toUrl('canonical', ['absolute' => TRUE])->toString());
        if (count($entity->getTranslationLanguages())) {
          foreach ($entity->getTranslationLanguages() as $lan => $tr) {
            $writer->startElement('xhtml:link');
            $writer->writeAttribute('rel', 'alternate');
            $writer->writeAttribute('hreflang', $lan);
            $writer->writeAttribute('href', $entity->getTranslation($lan)->toUrl('canonical', ['absolute' => TRUE] )->toString());
            $writer->endElement();
          }
        }
        $writer->writeElement('lastmod', date('Y-m-d\TH:i:s', $entity->getChangedTime()));
        $writer->writeElement('priority', $conf['priority']);
        $writer->endElement();
      }
    }
    $writer->endElement();
    $writer->endDocument();

    $response = new Response($writer->outputMemory(), Response::HTTP_OK, ['content-type' => 'application/xml']);
    return $response;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static($container->get('simple_sitemap.generator'));
  }

}











