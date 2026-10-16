<?php

declare(strict_types=1);

namespace Drupal\data_surface_entity\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\data_surface\SurfaceBuild\SurfaceRegistry;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The landing page: what was derived, and what was left out and why.
 *
 * Read from the registry, so it says what the site has now: every
 * content entity surface derived, each with the fields it carries, in
 * order, and each field its form display shows that it does not, with
 * the reason its deriver recorded. Below that, the basic pages written
 * most recently, each with a link to edit it through the surface.
 */
final class EntitySurfacesController extends ControllerBase {

  /**
   * The base id the content entity surfaces are derived under.
   */
  public const BASE = 'entity.content';

  /**
   * The surface the routes of this module serve.
   */
  public const PAGE = 'entity.content:node.page';

  /**
   * Constructs the controller.
   *
   * @param \Drupal\data_surface\SurfaceBuild\SurfaceRegistry $registry
   *   What discovery found, derived surfaces included.
   */
  public function __construct(
    protected readonly SurfaceRegistry $registry,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('data_surface.surface_registry'));
  }

  /**
   * Lists the derived content entity surfaces.
   *
   * @return array
   *   A render array.
   */
  public function index(): array {
    $build = [
      'intro' => [
        '#markup' => '<p>' . $this->t('Each bundle with a form display is a surface, derived from its fields\' typed data: one part per field the form shows, in the same order, each asking field access whether you may change it, only see it, or not see it at all. A basic page can be <a href=":add">added</a> and edited through it here, and every surface below is also a tool (<code>data_surface:&lt;surface&gt;:add</code>, <code>:edit</code>, <code>:edit:read</code>).', [
          ':add' => Url::fromRoute('data_surface_entity.node_page_add')->toString(),
        ]) . '</p>',
      ],
    ];
    $cacheability = [
      'tags' => ['entity_field_info', 'config:entity_form_display_list', 'node_list'],
      'contexts' => ['user.permissions'],
    ];
    foreach ($this->registry->getDefinitions() as $definition) {
      if ($definition->base !== self::BASE) {
        continue;
      }
      $derived = [];
      foreach ($this->registry->getDefinitions() as $child) {
        $prefix = 'field.item:' . substr($definition->id, strlen(self::BASE) + 1) . '.';
        if (str_starts_with($child->id, $prefix)) {
          $field = substr($child->id, strlen($prefix));
          $notes = array_map('strval', $child->notes);
          $derived[] = $notes === []
            ? ['#markup' => '<code>' . $field . '</code>']
            : ['#markup' => '<code>' . $field . '</code>: ' . implode(' ', array_map('htmlspecialchars', $notes))];
        }
      }
      $skipped = [];
      foreach ($definition->notes as $field => $reason) {
        $skipped[] = ['#markup' => '<code>' . htmlspecialchars((string) $field) . '</code>: ' . htmlspecialchars((string) $reason)];
      }
      $react = Url::fromUri('internal:/surface-react/' . $definition->id . '/add');
      $build[$definition->id] = [
        '#type' => 'details',
        '#open' => $definition->id === self::PAGE,
        '#title' => $this->t('@label, <code>@id</code>', [
          '@label' => (string) $definition->label,
          '@id' => $definition->id,
        ]),
        'description' => ['#markup' => '<p>' . htmlspecialchars((string) $definition->description) . ' ' . Link::fromTextAndUrl($this->t('React form'), $react)->toString() . '</p>'],
        'derived' => [
          '#theme' => 'item_list',
          '#title' => $this->t('Derived, in form order'),
          '#items' => $derived,
        ],
        'skipped' => [
          '#theme' => 'item_list',
          '#title' => $this->t('Left out'),
          '#items' => $skipped,
          '#empty' => $this->t('Nothing.'),
        ],
      ];
    }
    $build['pages'] = [
      '#theme' => 'item_list',
      '#title' => $this->t('Basic pages, newest first'),
      '#items' => $this->recentPages(),
      '#empty' => $this->t('None yet.'),
    ];
    $build['#cache'] = $cacheability;
    return $build;
  }

  /**
   * Lists the newest basic pages, each linked to its surface edit form.
   *
   * @return array
   *   Render array items.
   */
  protected function recentPages(): array {
    $storage = $this->entityTypeManager()->getStorage('node');
    $ids = $storage->getQuery()->accessCheck(TRUE)->condition('type', 'page')->sort('changed', 'DESC')->range(0, 5)->execute();
    $items = [];
    foreach ($storage->loadMultiple($ids) as $node) {
      $items[] = Link::createFromRoute($node->label() ?? (string) $node->id(), 'data_surface_entity.node_page_edit', ['id' => $node->id()])->toRenderable();
    }
    return $items;
  }

}
