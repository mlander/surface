<?php

declare(strict_types=1);

namespace Drupal\data_surface\Options;

use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\Validation\ConstraintManager;
use Psr\Log\LoggerInterface;

/**
 * Turns a definition's constraints into the list of values it allows.
 *
 * The single place that derives options from constraints. A value list
 * is declared once, as a constraint, and everything that needs it as a
 * list — the options widget today, schema emission later — asks here
 * rather than reading a constraint itself. That is what keeps the list
 * that validates and the list that is offered from being two lists.
 */
final class DataSurfaceOptions {

  /**
   * The instantiated resolvers, keyed by plugin ID.
   *
   * @var \Drupal\data_surface\Options\DataSurfaceOptionsResolverInterface[]
   */
  protected array $resolvers;

  /**
   * Resolved answers, keyed by the object ID of the definition asked.
   *
   * A per-request memo and nothing more. Resolving a list can mean
   * reading entity types, bundles, languages or countries, and a
   * generated form asks the same definition at least twice — once to
   * pick the widget, once to build the element. There is no
   * invalidation because there is nothing to invalidate: the memo dies
   * with the request, and a refined definition is a different object
   * with a different ID, so narrowing can never be served a stale list.
   *
   * Each entry holds the definition beside its answer. That is not
   * bookkeeping: it keeps the object alive, and an object that is alive
   * cannot have its spl_object_id() handed to a later object.
   *
   * @var array<int, array{definition: \Drupal\Core\TypedData\DataDefinitionInterface, set: \Drupal\data_surface\Options\OptionSet|null}>
   */
  protected array $resolved = [];

  /**
   * Constraint plugin IDs already reported as unregistered.
   *
   * @var array<string, true>
   */
  protected array $reported = [];

  /**
   * Constructs a DataSurfaceOptions service.
   *
   * @param \Drupal\data_surface\Options\DataSurfaceOptionsResolverManager $optionsManager
   *   The resolver plugin manager.
   * @param \Drupal\Core\Validation\ConstraintManager $constraintManager
   *   The constraint plugin manager, which instantiates a definition's
   *   declared constraints so resolvers read objects, not arrays.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger, for the one thing this service refuses to fail on: a
   *   definition naming a constraint plugin that is not registered.
   */
  public function __construct(
    protected readonly DataSurfaceOptionsResolverManager $optionsManager,
    protected readonly ConstraintManager $constraintManager,
    protected readonly LoggerInterface $logger,
  ) {
  }

  /**
   * Resolves the values a definition allows, with their labels.
   *
   * Every constraint on the definition has to hold at once, so when more
   * than one of them names a list the answer is their intersection.
   *
   * A constraint whose plugin is not registered is skipped rather than
   * fatal. Reading options is a courtesy this service does for whoever
   * asks; a missing plugin is a site problem and belongs in the log, not
   * in an exception thrown out of widget selection, where it would take
   * down a form over a value list nobody could have offered anyway.
   * Validation still sees the same constraint and refuses on its own
   * terms.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition to read.
   *
   * @return \Drupal\data_surface\Options\OptionSet|null
   *   The allowed values, or NULL when no constraint names a list.
   */
  public function resolve(DataDefinitionInterface $definition): ?OptionSet {
    $id = spl_object_id($definition);
    if (array_key_exists($id, $this->resolved)) {
      return $this->resolved[$id]['set'];
    }
    $resolved = NULL;
    foreach ($definition->getConstraints() as $name => $options) {
      try {
        $constraint = $this->constraintManager->create($name, $options);
      }
      catch (PluginNotFoundException) {
        $this->reportUnregisteredConstraint((string) $name);
        continue;
      }
      foreach ($this->resolvers() as $resolver) {
        if (!$resolver->applies($constraint)) {
          continue;
        }
        $set = $resolver->resolve($constraint, $definition);
        $resolved = $resolved === NULL ? $set : $resolved->intersect($set);
      }
    }
    $this->resolved[$id] = ['definition' => $definition, 'set' => $resolved];
    return $resolved;
  }

  /**
   * Logs an unregistered constraint plugin, once per request.
   *
   * Once, because the same definition is read several times per form and
   * the same missing plugin would otherwise fill the log with one entry
   * per element per build.
   *
   * @param string $name
   *   The constraint plugin ID the definition named.
   */
  protected function reportUnregisteredConstraint(string $name): void {
    if (isset($this->reported[$name])) {
      return;
    }
    $this->reported[$name] = TRUE;
    $this->logger->warning('The constraint plugin %constraint is not registered, so the values it allows cannot be offered as options. Definitions carrying it are rendered as free input and the constraint still refuses invalid values when they are validated.', [
      '%constraint' => $name,
    ]);
  }

  /**
   * Gets every resolver, instantiated once per service instance.
   *
   * @return \Drupal\data_surface\Options\DataSurfaceOptionsResolverInterface[]
   *   The resolvers, keyed by plugin ID.
   */
  protected function resolvers(): array {
    if (!isset($this->resolvers)) {
      $this->resolvers = [];
      foreach (array_keys($this->optionsManager->getDefinitions()) as $id) {
        /** @var \Drupal\data_surface\Options\DataSurfaceOptionsResolverInterface $resolver */
        $resolver = $this->optionsManager->createInstance($id);
        $this->resolvers[$id] = $resolver;
      }
    }
    return $this->resolvers;
  }

}
