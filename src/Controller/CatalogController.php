<?php

declare(strict_types=1);

namespace Drupal\surface\Controller;

use Drupal\Component\Utility\Html;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\surface\ResourceBuild\CatalogDocument;
use Drupal\surface\ResourceBuild\SiteCatalog;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The site's catalog as a page: what `drush surface:catalog` lists.
 *
 * One table of every resource and structure, one row per operation, the
 * rows the command's table prints (CatalogDocument::rows()), each door
 * linked where the module that opens it gives a URL
 * (SiteCatalog::links()); a second table of what did not build; and one
 * page per resource or structure, what `--resource` shows. The data is
 * built for no account, as the command's is, so the page says so: the
 * documents a request answers differ by account.
 *
 * Decision: see docs/decisions.md#the-catalog-is-the-sites-live-map.
 */
final class CatalogController extends ControllerBase {

  /**
   * Constructs a CatalogController.
   *
   * @param \Drupal\surface\ResourceBuild\SiteCatalog $catalog
   *   The site catalog.
   */
  public function __construct(
    protected readonly SiteCatalog $catalog,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('surface.site_catalog'));
  }

  /**
   * Renders the catalog: every operation with its doors, then the refusals.
   *
   * @return array
   *   A render array.
   */
  public function catalog(): array {
    $catalog = $this->catalog->describe();
    $refused = $this->catalog->refused();
    $counts = CatalogDocument::counts($catalog);
    $rows = [];
    foreach (CatalogDocument::rows($catalog) as $row) {
      $rows[] = [
        $row['read'] === NULL ? ['data' => $this->resourceLink($row['resource'])] : $row['resource'],
        $row['kind'],
        $row['operation'],
        $row['permission'],
        $row['needs'],
        ['data' => $this->doors($row, $row['operation_id'] === NULL ? [] : $this->catalog->links($row['resource'], $row['operation_id']))],
      ];
    }
    return [
      'account' => $this->noAccount(),
      'summary' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('@resources resources and @structures structures, @operations operations, @read structures read from a schema; @refused refused.', [
          '@resources' => $counts['resources'],
          '@structures' => $counts['structures'],
          '@operations' => $counts['operations'],
          '@read' => $counts['read'],
          '@refused' => count($refused),
        ]),
      ],
      'catalog' => [
        '#type' => 'table',
        '#header' => [
          $this->t('Resource or structure'),
          $this->t('Kind'),
          $this->t('Operation'),
          $this->t('Permission'),
          $this->t('Needs'),
          $this->t('Doors'),
        ],
        '#rows' => $rows,
        '#attributes' => ['id' => 'surface-catalog'],
      ],
      'refused' => $this->refusals($refused),
      '#cache' => ['max-age' => 0],
    ];
  }

  /**
   * Renders one resource or structure in full: what `--resource` shows.
   *
   * @param string $resource
   *   Its id, or the name a structure read from a schema is built by.
   *
   * @return array
   *   A render array.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
   *   When discovery found nothing by that name.
   */
  public function resource(string $resource): array {
    try {
      $entry = $this->catalog->resource($resource);
    }
    catch (\InvalidArgumentException) {
      throw new NotFoundHttpException();
    }
    $operations = [];
    foreach ($entry['operations'] as $operation) {
      $row = ['doors' => $operation['doors'], 'read' => NULL];
      $operations[] = [
        $operation['id'] . ' (' . $operation['kind'] . ')',
        (string) $operation['label'],
        $operation['permission'] ?? 'none',
        $operation['parameters'] === [] ? 'nothing' : implode(', ', $operation['parameters']),
        ['data' => $this->doors($row, $entry['read'] === NULL ? $this->catalog->links($entry['id'], $operation['id']) : [])],
      ];
    }
    $keys = [];
    foreach ($entry['keys'] as $key) {
      $keys[] = [
        $key['key'] . ($key['variant'] === NULL ? '' : ' (' . $key['variant'] . ')'),
        $key['type'] . ($key['required'] ? ', required' : ''),
        CatalogDocument::json($key['default'], FALSE),
        $key['constraints'] === [] ? '' : CatalogDocument::json($key['constraints'], FALSE),
        ['data' => ['#theme' => 'item_list', '#items' => CatalogDocument::notes($key)]],
      ];
    }
    return [
      'account' => $this->noAccount(),
      'facts' => [
        '#theme' => 'item_list',
        '#items' => [
          ...(($entry['label'] ?? NULL) === NULL ? [] : [$this->t('Label: @label', ['@label' => $entry['label']])]),
          ...(($entry['description'] ?? NULL) === NULL ? [] : [$this->t('Description: @description', ['@description' => $entry['description']])]),
          $this->t('Kind: @kind', ['@kind' => $entry['kind']]),
          $this->t('Declared by: @class (@module)', [
            '@class' => $entry['class'] ?? 'derived under ' . $entry['deriver'],
            '@module' => $entry['module'],
          ]),
          $this->t('Identity: @identity', ['@identity' => $entry['identity'] === [] ? 'none' : implode(', ', $entry['identity'])]),
          $this->t('Storage: @storage', ['@storage' => $entry['storage'] ?? 'none']),
          $this->t('Access: @access', ['@access' => CatalogDocument::access($entry['answers'])]),
          $this->t('Keys read in: @context', ['@context' => $entry['built'] ?? 'nothing: it did not build']),
        ],
        '#attributes' => ['id' => 'surface-catalog-facts'],
      ],
      'operations' => [
        '#type' => 'table',
        '#caption' => $this->t('Operations'),
        '#header' => [
          $this->t('Operation'),
          $this->t('Label'),
          $this->t('Permission'),
          $this->t('Needs'),
          $this->t('Doors'),
        ],
        '#rows' => $operations,
        '#empty' => $this->t('No operation: its host or its parent configures it.'),
        '#attributes' => ['id' => 'surface-catalog-operations'],
      ],
      'keys' => [
        '#type' => 'table',
        '#caption' => $this->t('Keys'),
        '#header' => [
          $this->t('Key'),
          $this->t('Type'),
          $this->t('Default'),
          $this->t('Constraints'),
          $this->t('Notes'),
        ],
        '#rows' => $keys,
        '#empty' => $this->t('No keys: it did not build.'),
        '#attributes' => ['id' => 'surface-catalog-keys'],
      ],
      'refused' => $this->refusals($entry['refused']),
      'back' => [
        '#type' => 'link',
        '#title' => $this->t('Every resource and structure'),
        '#url' => Url::fromRoute('surface.catalog'),
      ],
      '#cache' => ['max-age' => 0],
    ];
  }

  /**
   * Titles one resource's page with its id.
   *
   * @param string $resource
   *   Its id, or the name it is built by.
   *
   * @return string
   *   The title.
   */
  public function title(string $resource): string {
    return $resource;
  }

  /**
   * Says the listing is built for no account.
   *
   * @return array
   *   A render array: a warning.
   */
  protected function noAccount(): array {
    return [
      '#theme' => 'status_messages',
      '#message_list' => ['warning' => [$this->t('This listing is built for no account, as the tool listing is, so it is the same whoever reads it. The documents each request answers (the schema for an account, its tool listing, what it may change) differ by account.')]],
      '#status_headings' => ['warning' => $this->t('Warning message')],
    ];
  }

  /**
   * Links a resource or structure to its own page.
   *
   * @param string $resource
   *   Its id.
   *
   * @return array
   *   A render array.
   */
  protected function resourceLink(string $resource): array {
    return [
      '#type' => 'link',
      '#title' => $resource,
      '#url' => Url::fromRoute('surface.catalog.resource', ['resource' => $resource]),
    ];
  }

  /**
   * Renders a row's doors: each by its name, linked where it has a URL.
   *
   * @param array $row
   *   A row, as CatalogDocument::rows() lists it.
   * @param array<string, \Drupal\Core\Url> $links
   *   The URL of each door that has one.
   *
   * @return array
   *   A render array: a list of the doors, each item carrying its name in
   *   `data-door`; the table's text where there are none.
   */
  protected function doors(array $row, array $links): array {
    if (($row['doors'] ?? []) === [] || $row['read'] !== NULL) {
      return ['#plain_text' => CatalogDocument::doorNames($row + ['read' => NULL])];
    }
    $items = [];
    foreach ($row['doors'] as $door => $what) {
      $items[] = [
        '#wrapper_attributes' => ['data-door' => $door],
        // A child with no #type, #theme or #markup reads as a nested list.
        'name' => ['#markup' => Html::escape($door) . ': '],
        'what' => isset($links[$door])
          ? ['#type' => 'link', '#title' => $what, '#url' => $links[$door]]
          : ['#type' => 'html_tag', '#tag' => 'code', 'text' => ['#plain_text' => $what]],
      ];
    }
    return ['#theme' => 'item_list', '#items' => $items];
  }

  /**
   * Renders what did not build.
   *
   * @param array $refused
   *   What SiteCatalog::refused() answers.
   *
   * @return array
   *   A render array: a heading and a table.
   */
  protected function refusals(array $refused): array {
    $rows = [];
    foreach ($refused as $refusal) {
      $rows[] = [
        $refusal['resource'] ?? $refusal['stage'],
        $refusal['operation'] ?? 'the whole',
        $refusal['message'],
        $refusal['file'] ?? 'none',
      ];
    }
    return [
      'heading' => ['#type' => 'html_tag', '#tag' => 'h2', '#value' => $this->t('Refused')],
      'table' => [
        '#type' => 'table',
        '#header' => [$this->t('Resource or structure'), $this->t('Operation'), $this->t('Message'), $this->t('File')],
        '#rows' => $rows,
        '#empty' => $this->t('Nothing discovery found was refused: every operation of every resource and structure built.'),
        '#attributes' => ['id' => 'surface-catalog-refused'],
      ],
    ];
  }

}
