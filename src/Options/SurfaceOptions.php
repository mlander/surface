<?php

declare(strict_types=1);

namespace Drupal\surface\Options;

use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\Validation\ConstraintManager;
use Drupal\surface\Pipeline\PipelineGuard;
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
final class SurfaceOptions {

  use DependencySerializationTrait {
    __sleep as private sleepDependencies;
    __wakeup as private wakeDependencies;
  }

  /**
   * The instantiated resolvers, keyed by plugin ID.
   *
   * @var \Drupal\surface\Options\SurfaceOptionsResolverInterface[]
   */
  protected array $resolvers;

  /**
   * Resolved answers, keyed by the definition asked.
   *
   * An identity memo and nothing more. Resolving a list can mean reading
   * entity types, bundles, languages or countries, and a generated form
   * asks the same definition at least twice: once to pick the widget,
   * once to build the element. There is no invalidation because there is
   * nothing to invalidate: a refined definition is a different object, so
   * narrowing can never be served a stale list.
   *
   * Weak, so an entry lives exactly as long as its definition. Every
   * refine makes new definitions, and a worker that outlives a request
   * (an MCP stdio server, a queue, a Drush batch) would otherwise keep
   * every one it ever resolved. FALSE stands for no list: no constraint
   * on the definition names one.
   *
   * @var \WeakMap<\Drupal\Core\TypedData\DataDefinitionInterface, \Drupal\surface\Options\OptionSet|false>
   */
  protected \WeakMap $resolved;

  /**
   * Constraint plugin IDs already reported as unregistered.
   *
   * @var array<string, true>
   */
  protected array $reported = [];

  /**
   * Constructs a SurfaceOptions service.
   *
   * @param \Drupal\surface\Options\SurfaceOptionsResolverManager $optionsManager
   *   The resolver plugin manager.
   * @param \Drupal\Core\Validation\ConstraintManager $constraintManager
   *   The constraint plugin manager, which instantiates a definition's
   *   declared constraints so resolvers read objects, not arrays.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger, for the one thing this service refuses to fail on: a
   *   definition naming a constraint plugin that is not registered.
   */
  public function __construct(
    protected readonly SurfaceOptionsResolverManager $optionsManager,
    protected readonly ConstraintManager $constraintManager,
    protected readonly LoggerInterface $logger,
  ) {
    $this->resolved = new \WeakMap();
  }

  /**
   * {@inheritdoc}
   */
  public function __sleep(): array {
    // PHP refuses to serialize a WeakMap, and an identity memo means
    // nothing to another process; the resolvers are made again on use.
    return array_values(array_diff($this->sleepDependencies(), ['resolved', 'resolvers']));
  }

  /**
   * {@inheritdoc}
   */
  public function __wakeup(): void {
    $this->wakeDependencies();
    $this->resolved = new \WeakMap();
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
   * @return \Drupal\surface\Options\OptionSet|null
   *   The allowed values, or NULL when no constraint names a list.
   */
  public function resolve(DataDefinitionInterface $definition): ?OptionSet {
    if ($this->resolved->offsetExists($definition)) {
      return $this->resolved[$definition] ?: NULL;
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
        // Decision: see
        // docs/decisions.md#nothing-an-operation-runs-reads-the-request.
        if (!PipelineGuard::onBehalf(PipelineGuard::OPTIONS, get_class($resolver), 'applies', static fn (): bool => $resolver->applies($constraint))) {
          continue;
        }
        $set = PipelineGuard::onBehalf(PipelineGuard::OPTIONS, get_class($resolver), 'resolve', static fn (): OptionSet => $resolver->resolve($constraint, $definition));
        $resolved = $resolved === NULL ? $set : $resolved->intersect($set);
      }
    }
    $this->resolved[$definition] = $resolved ?? FALSE;
    return $resolved;
  }

  /**
   * Answers core's options question for one key.
   *
   * The possible values are the declared definition's list, what the full
   * schema states; the settable values are the refined definition's list,
   * what the schema for these answers states, or the declared one when no
   * refined definition is handed in.
   *
   * Decision: see docs/decisions.md#our-options-answer-cores-question.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $declared
   *   The key's definition as declared.
   * @param \Drupal\Core\TypedData\DataDefinitionInterface|null $refined
   *   (optional) The key's definition refined for a set of values.
   *
   * @return \Drupal\surface\Options\OptionsProvider
   *   Core's OptionsProviderInterface for the key, with the lists'
   *   cacheability on cacheability().
   */
  public function provider(DataDefinitionInterface $declared, ?DataDefinitionInterface $refined = NULL): OptionsProvider {
    return new OptionsProvider($declared, $refined, $this);
  }

  /**
   * Forgets the answers read from what is being invalidated.
   *
   * A list read from the site (a module's config entities, say) is
   * answered with its cacheability; a definition kept for the rest of the
   * request is asked again once a tag that list named is invalidated, so
   * a venue added by one call is offered by the next in the same worker.
   *
   * @param string[] $tags
   *   The tags being invalidated.
   *
   * @internal
   *
   * @see \Drupal\surface\ResourceBuild\ResourceRegistryInvalidator
   */
  public function invalidateTags(array $tags): void {
    $forget = [];
    foreach ($this->resolved as $definition => $set) {
      if ($set instanceof OptionSet && array_intersect($set->getCacheTags(), $tags) !== []) {
        $forget[] = $definition;
      }
    }
    foreach ($forget as $definition) {
      unset($this->resolved[$definition]);
    }
  }

  /**
   * Answers whether a constraint is stated whole by the list read from it.
   *
   * A constraint a resolver says is its list (`exact: TRUE` on the
   * resolver's attribute) accepts every value of that list and nothing
   * else, so a schema stating the list states the constraint.
   *
   * @param string $constraint
   *   The constraint plugin ID.
   *
   * @return bool
   *   TRUE when a resolver reading it says its list is exact.
   */
  public function isStatedByList(string $constraint): bool {
    $class = $this->constraintManager->getDefinition($constraint, FALSE)['class'] ?? NULL;
    if (!is_string($class)) {
      return FALSE;
    }
    foreach ($this->optionsManager->getDefinitions() as $definition) {
      if (empty($definition['exact']) || !is_string($definition['constraint'] ?? NULL)) {
        continue;
      }
      // A resolver reads its constraint and every subclass of it, as
      // applies() does.
      $read = $this->constraintManager->getDefinition($definition['constraint'], FALSE)['class'] ?? NULL;
      if (is_string($read) && is_a($class, $read, TRUE)) {
        return TRUE;
      }
    }
    return FALSE;
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
   * @return \Drupal\surface\Options\SurfaceOptionsResolverInterface[]
   *   The resolvers, keyed by plugin ID.
   */
  protected function resolvers(): array {
    if (!isset($this->resolvers)) {
      $this->resolvers = [];
      foreach (array_keys($this->optionsManager->getDefinitions()) as $id) {
        /** @var \Drupal\surface\Options\SurfaceOptionsResolverInterface $resolver */
        $resolver = $this->optionsManager->createInstance($id);
        assert(PipelineGuard::holdsNothingDoorSpecific($resolver, PipelineGuard::OPTIONS));
        $this->resolvers[$id] = $resolver;
      }
    }
    return $this->resolvers;
  }

}
