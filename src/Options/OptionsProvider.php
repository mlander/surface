<?php

declare(strict_types=1);

namespace Drupal\data_surface\Options;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\ListDataDefinitionInterface;
use Drupal\Core\TypedData\OptionsProviderInterface;

/**
 * Answers core's options question for one key, from its resolved lists.
 *
 * Core asks "which values may this hold, and with what labels" through
 * OptionsProviderInterface, in two halves. The possible half is every
 * value the key may hold, which is the declared definition's list, what
 * the full schema states. The settable half is what may be set now,
 * which is the refined definition's list, what the schema for these
 * answers states; with no refined definition handed in it is the
 * declared list. Both are read from the options service, so a resolver
 * stays the one source of a list and this class only translates.
 *
 * The account core's methods take is not read. What an account may set
 * is settled when the definition is refined for it, so hand in the
 * refined definition built for that account.
 *
 * A key of a list offers what one item may be, as the choice widgets
 * read it. A key with no list answers an empty array, as core's
 * interface allows.
 *
 * Decision: see docs/decisions.md#our-options-answer-cores-question.
 *
 * @see \Drupal\data_surface\Options\DataSurfaceOptions::provider()
 */
final class OptionsProvider implements OptionsProviderInterface {

  /**
   * Constructs an OptionsProvider.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $declared
   *   The key's definition as declared.
   * @param \Drupal\Core\TypedData\DataDefinitionInterface|null $refined
   *   The key's definition refined for a set of values, or NULL.
   * @param \Drupal\data_surface\Options\DataSurfaceOptions $options
   *   The options service, which reads each definition's list.
   */
  public function __construct(
    private readonly DataDefinitionInterface $declared,
    private readonly ?DataDefinitionInterface $refined,
    private readonly DataSurfaceOptions $options,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function getPossibleValues(?AccountInterface $account = NULL): array {
    return array_keys($this->getPossibleOptions($account));
  }

  /**
   * {@inheritdoc}
   *
   * The declared definition's list: every value the key may hold.
   */
  public function getPossibleOptions(?AccountInterface $account = NULL): array {
    return $this->set($this->declared)->options ?? [];
  }

  /**
   * {@inheritdoc}
   */
  public function getSettableValues(?AccountInterface $account = NULL): array {
    return array_keys($this->getSettableOptions($account));
  }

  /**
   * {@inheritdoc}
   *
   * The refined definition's list when one was handed in, else the
   * declared one.
   */
  public function getSettableOptions(?AccountInterface $account = NULL): array {
    return $this->set($this->refined ?? $this->declared)->options ?? [];
  }

  /**
   * Says how long the answers may be reused.
   *
   * Core's interface has no place for it. It merges the cacheability of
   * both lists, since an answer holding either half is reusable only as
   * long as the shorter-lived one.
   *
   * @return \Drupal\Core\Cache\CacheableMetadata
   *   The lists' tags, contexts and max-age; permanent for a key with no
   *   list.
   */
  public function cacheability(): CacheableMetadata {
    $cacheability = new CacheableMetadata();
    foreach ([$this->declared, $this->refined] as $definition) {
      $set = $definition === NULL ? NULL : $this->set($definition);
      if ($set !== NULL) {
        $cacheability->addCacheableDependency($set);
      }
    }
    return $cacheability;
  }

  /**
   * Reads a definition's list, a list key's from its item definition.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition.
   *
   * @return \Drupal\data_surface\Options\OptionSet|null
   *   The list, or NULL when no constraint names one.
   */
  private function set(DataDefinitionInterface $definition): ?OptionSet {
    return $this->options->resolve($definition instanceof ListDataDefinitionInterface ? $definition->getItemDefinition() : $definition);
  }

}
