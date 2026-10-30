<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Variants;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\State\StateInterface;
use Drupal\surface\Resource\EntrySchemasInterface;
use Drupal\surface\Resource\SiteList;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The poster's layouts: one variant each, from the schema it carries.
 *
 * Three layouts are always listed; State may list more, and defs they
 * share, cleared by the cache tag this declares, as a site's
 * configuration would be. Those State lists are given with their schema
 * and the defs, or, once State says LAZY, by value and label alone, each
 * schema and the defs answered when asked (entrySchema(), entryDefs()),
 * which READ records.
 */
final class PosterLayouts implements EntrySchemasInterface, ContainerInjectionInterface {

  /**
   * The cache tag of the list.
   */
  public const TAG = 'surface_resource_test:poster_layouts';

  /**
   * The State key a test lists more layouts under, by value.
   */
  public const STATE = 'surface_resource_test.poster_layouts';

  /**
   * The State key a test lists defs the layouts share under, by name.
   */
  public const DEFS = 'surface_resource_test.poster_layout_defs';

  /**
   * The State key that has the layouts State lists read when asked.
   */
  public const LAZY = 'surface_resource_test.poster_layouts_lazy';

  /**
   * What was asked of entrySchema() and entryDefs() (`$defs`), in order.
   *
   * @var string[]
   */
  public static array $read = [];

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
            'not' => ['const' => 'Untitled'],
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
  public function list(): SiteList {
    $list = (new SiteList())->addCacheTags([self::TAG]);
    $lazy = (bool) $this->state->get(self::LAZY, FALSE);
    foreach (self::LAYOUTS as $value => $layout) {
      $list->add((string) $value, $layout['label'], schema: $layout['schema']);
    }
    foreach ((array) $this->state->get(self::STATE, []) as $value => $layout) {
      $list->add((string) $value, $layout['label'], schema: $lazy ? NULL : $layout['schema']);
    }
    $defs = $lazy ? [] : $this->entryDefs();
    return $defs === [] ? $list : $list->addDefs($defs);
  }

  /**
   * {@inheritdoc}
   */
  public function entrySchema(string $id): array {
    self::$read[] = $id;
    return (self::LAYOUTS + (array) $this->state->get(self::STATE, []))[$id]['schema'];
  }

  /**
   * {@inheritdoc}
   */
  public function entryDefs(): array {
    if ($this->state->get(self::LAZY, FALSE)) {
      self::$read[] = '$defs';
    }
    return (array) $this->state->get(self::DEFS, []);
  }

}
