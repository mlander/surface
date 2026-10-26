<?php

declare(strict_types=1);

namespace Drupal\data_surface_examples_references;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\State\StateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The nodes example 16's calls name, written when the module is installed.
 *
 * An article the sponsorship may announce, a draft article that only an
 * account bypassing node access may reference, and a page, of a bundle the
 * announcement does not take. Their ids are kept in State, where the
 * calls read them (NODES).
 */
final class Sponsorship implements ContainerInjectionInterface {

  /**
   * The State key holding the ids, by name.
   */
  public const NODES = 'data_surface_examples_references.nodes';

  /**
   * Each node, by name: its bundle, its title and whether it is published.
   */
  public const WRITTEN = [
    'article' => ['type' => 'article', 'title' => 'Sponsor packages', 'status' => 1],
    'draft' => ['type' => 'article', 'title' => 'Draft sponsor terms', 'status' => 0],
    'page' => ['type' => 'page', 'title' => 'Sponsor stand map', 'status' => 1],
  ];

  /**
   * Constructs a Sponsorship.
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
   * Writes the nodes, each whose bundle the site has, and keeps their ids.
   *
   * Once: nodes written already are kept.
   */
  public function writeNodes(): void {
    if ($this->ids() !== []) {
      return;
    }
    $storage = $this->entityTypeManager->getStorage('node');
    $types = $this->entityTypeManager->getStorage('node_type');
    $ids = [];
    foreach (self::WRITTEN as $name => $values) {
      if ($types->load($values['type']) === NULL) {
        continue;
      }
      $node = $storage->create($values + ['uid' => 1]);
      $node->save();
      $ids[$name] = (int) $node->id();
    }
    $this->state->set(self::NODES, $ids);
  }

  /**
   * Deletes the nodes writeNodes() wrote, and forgets their ids.
   */
  public function deleteNodes(): void {
    $storage = $this->entityTypeManager->getStorage('node');
    $storage->delete($storage->loadMultiple(array_values($this->ids())));
    $this->state->delete(self::NODES);
  }

  /**
   * Gets the ids of the nodes, by name.
   *
   * @return array<string, int>
   *   Each node's id, by its name in WRITTEN.
   */
  public function ids(): array {
    return (array) $this->state->get(self::NODES, []);
  }

}
