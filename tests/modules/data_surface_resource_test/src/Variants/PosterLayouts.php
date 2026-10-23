<?php

declare(strict_types=1);

namespace Drupal\data_surface_resource_test\Variants;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\State\StateInterface;
use Drupal\data_surface\Resource\VariantList;
use Drupal\data_surface\Resource\VariantsFromSiteInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The poster's layouts: one variant each, from the schema it carries.
 *
 * Three layouts are always listed; State may list more, cleared by the
 * cache tag this declares, as a site's configuration would be.
 */
final class PosterLayouts implements VariantsFromSiteInterface, ContainerInjectionInterface {

  /**
   * The cache tag of the list.
   */
  public const TAG = 'data_surface_resource_test:poster_layouts';

  /**
   * The State key a test lists more layouts under, by value.
   */
  public const STATE = 'data_surface_resource_test.poster_layouts';

  /**
   * The layouts always listed: a label and a schema each, by value.
   */
  public const LAYOUTS = [
    'banner' => [
      'label' => 'Banner, as listed',
      'schema' => [
        'type' => 'object',
        'properties' => ['ignored' => ['type' => 'string']],
      ],
    ],
    'grid' => [
      'label' => 'Grid',
      'schema' => [
        '$schema' => 'http://json-schema.org/draft-04/schema#',
        'type' => 'object',
        'required' => ['columns'],
        'properties' => [
          'columns' => [
            'type' => 'integer',
            'title' => 'Columns',
            'minimum' => 0,
            'exclusiveMinimum' => TRUE,
            'maximum' => 4,
            'default' => 2,
          ],
          'gap' => [
            'type' => 'string',
            'title' => 'Gap',
            'enum' => ['none', 'small', 'large'],
            'default' => 'small',
          ],
        ],
        'additionalProperties' => FALSE,
      ],
    ],
    'strip' => [
      'label' => 'Strip',
      'schema' => [
        'type' => 'object',
        'properties' => [
          'caption' => [
            'type' => ['string', 'null'],
            'title' => 'Caption',
            'maxLength' => 60,
          ],
          'tags' => [
            'type' => ['array', 'null'],
            'title' => 'Tags',
            'items' => ['type' => 'string'],
            'uniqueItems' => TRUE,
          ],
        ],
        'additionalProperties' => FALSE,
      ],
    ],
  ];

  /**
   * Constructs the provider.
   *
   * @param \Drupal\Core\State\StateInterface $state
   *   The State, where a test lists more layouts.
   */
  public function __construct(
    protected readonly StateInterface $state,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('state'));
  }

  /**
   * {@inheritdoc}
   */
  public function variants(): VariantList {
    $list = (new VariantList())->addCacheTags([self::TAG]);
    foreach (self::LAYOUTS + (array) $this->state->get(self::STATE, []) as $value => $layout) {
      $list->add((string) $value, $layout['label'], $layout['schema']);
    }
    return $list;
  }

}
