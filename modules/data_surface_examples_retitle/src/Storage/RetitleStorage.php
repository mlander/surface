<?php

declare(strict_types=1);

namespace Drupal\data_surface_examples_retitle\Storage;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\State\StateInterface;
use Drupal\data_surface\Pipeline\StorageViolationsException;
use Drupal\data_surface\Pipeline\Violation;
use Drupal\data_surface\Pipeline\ViolationSet;
use Drupal\data_surface\Resource\OperationContext;
use Drupal\data_surface\Resource\ResourceStorageInterface;
use Drupal\data_surface_examples_retitle\Retitle;
use Drupal\data_surface_examples_retitle\TitleRewriter;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Example 17: writes each node's title, one save per node.
 *
 * The retitle keeps no values of its own: what it writes is the nodes'
 * titles. It only ever sees items every gate allowed, since the gates are
 * asked before its prepare(). prepare() loads each node and works out its
 * new title, the one sent or the rewritten one, which a dry run answers;
 * commit() writes them and keeps the list in State, where load() reads it
 * back as the output a write answers.
 */
final class RetitleStorage implements ResourceStorageInterface, ContainerInjectionInterface {

  /**
   * Constructs a RetitleStorage.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager, which loads and saves the nodes.
   * @param \Drupal\Core\State\StateInterface $state
   *   The State, which keeps the titles the last retitle wrote.
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
   * {@inheritdoc}
   *
   * No values: a retitle names its nodes each time. The titles the last
   * one wrote, which are no key, are what a write reads back as its
   * output.
   */
  public function load(OperationContext $context): array {
    return ['titles' => array_values((array) $this->state->get(Retitle::LAST, []))];
  }

  /**
   * {@inheritdoc}
   *
   * Each item's node, its title, and the title it takes.
   */
  public function prepare(OperationContext $context, array $values, AccountInterface $account): array {
    $style = is_string($values['style'] ?? NULL) ? $values['style'] : TitleRewriter::TITLE;
    $storage = $this->entityTypeManager->getStorage('node');
    $titles = [];
    $refused = [];
    foreach (array_values((array) ($values['items'] ?? [])) as $delta => $item) {
      $node = $storage->load($item['node'] ?? 0);
      if (!$node instanceof NodeInterface) {
        $refused[] = new Violation('items', $delta . '.node', sprintf('There is no node %s.', (string) ($item['node'] ?? '')), Violation::NOT_FOUND);
        continue;
      }
      $sent = $item['new_title'] ?? NULL;
      $new = is_string($sent) && $sent !== '' ? $sent : TitleRewriter::rewrite((string) $node->getTitle(), $style);
      if (mb_strlen($new) > 255) {
        $refused[] = new Violation('items', $delta . '.new_title', sprintf('The title %s rewrites to is longer than 255 characters.', $node->label()), 'length');
        continue;
      }
      $titles[] = ['node' => (int) $node->id(), 'old' => (string) $node->getTitle(), 'new' => $new];
    }
    if ($refused !== []) {
      throw new StorageViolationsException(new ViolationSet($refused));
    }
    return ['titles' => $titles];
  }

  /**
   * {@inheritdoc}
   *
   * One save per node whose title changes, then the list kept.
   */
  public function commit(OperationContext $context, array $prepared, AccountInterface $account): void {
    $storage = $this->entityTypeManager->getStorage('node');
    foreach ($prepared['titles'] ?? [] as ['node' => $id, 'old' => $old, 'new' => $new]) {
      $node = $storage->load($id);
      if ($node instanceof NodeInterface && $old !== $new) {
        $node->setTitle($new)->save();
      }
    }
    $this->state->set(Retitle::LAST, $prepared['titles'] ?? []);
  }

}
