<?php

declare(strict_types=1);

namespace Drupal\data_surface_examples_retitle;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\State\StateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The pages example 17's calls retitle, written when the module is installed.
 *
 * Three basic pages, their ids kept in State, where the calls read them
 * (NODES). The third one's title is locked while a State flag is set
 * (LOCKED), by the module's field access hook, to show the field gate
 * refusing a page the entity gate allows.
 */
final class Retitle implements ContainerInjectionInterface {

  /**
   * The State key holding the ids, by name.
   */
  public const NODES = 'data_surface_examples_retitle.nodes';

  /**
   * The State flag that locks the third page's title.
   */
  public const LOCKED = 'data_surface_examples_retitle.locked';

  /**
   * The State key holding the titles the last retitle wrote.
   */
  public const LAST = 'data_surface_examples_retitle.last';

  /**
   * Each page, by name: its title as written.
   */
  public const WRITTEN = [
    'one' => 'volunteer briefing for the night shift',
    'two' => 'WHERE TO PARK ON FESTIVAL DAYS',
    'three' => 'menu of the food stalls',
  ];

  /**
   * Constructs a Retitle.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\State\StateInterface $state
   *   The State, which keeps the ids.
   */
  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly StateInterface $state,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('entity_type.manager'), $container->get('state'));
  }

  /**
   * Writes the pages, when the site has the page bundle, and keeps their ids.
   *
   * Once: pages written already are kept.
   */
  public function writeNodes(): void {
    if ($this->ids() !== [] || $this->entityTypeManager->getStorage('node_type')->load('page') === NULL) {
      return;
    }
    $storage = $this->entityTypeManager->getStorage('node');
    $ids = [];
    foreach (self::WRITTEN as $name => $title) {
      $node = $storage->create(['type' => 'page', 'title' => $title, 'status' => 1, 'uid' => 1]);
      $node->save();
      $ids[$name] = (int) $node->id();
    }
    $this->state->set(self::NODES, $ids);
  }

  /**
   * Deletes the pages writeNodes() wrote, and forgets what State kept.
   */
  public function deleteNodes(): void {
    $storage = $this->entityTypeManager->getStorage('node');
    $storage->delete($storage->loadMultiple(array_values($this->ids())));
    $this->state->deleteMultiple([self::NODES, self::LOCKED, self::LAST]);
  }

  /**
   * Gets the ids of the pages, by name.
   *
   * @return array<string, int>
   *   Each page's id, by its name in WRITTEN.
   */
  public function ids(): array {
    return (array) $this->state->get(self::NODES, []);
  }

}
