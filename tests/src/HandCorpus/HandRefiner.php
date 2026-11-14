<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\HandCorpus;

use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\ListDataDefinition;
use Drupal\surface\Refinement\DoesNotApply;
use Drupal\surface\SurfaceRefinerInterface;

/**
 * Refines a key by what one gate key holds, for the hand corpus.
 *
 * For each value of the gate, a list of steps, each an operation and the
 * dotted path below the key it applies at ('' for the key itself): `out`
 * rules the place out, `choice` adds a Choice of the choices given, `req`
 * makes it required, `label` gives it the label given, `len` adds a
 * Length and `count` a Count of the maximum given, each on a copy;
 * `clone` goes on with a copy of the key's definition, and `same` hands
 * back the definition as it is. A list's item is the place below a
 * list, whatever its path says.
 */
final class HandRefiner implements SurfaceRefinerInterface {

  /**
   * Constructs a refiner.
   *
   * @param array<array-key, list<array{0: string, 1: string, 2?: mixed}>> $steps
   *   The steps by the gate's value as text: 'true' and 'false' for a
   *   boolean, '' for no value.
   * @param string $gate
   *   The key whose value chooses the steps.
   */
  public function __construct(
    protected readonly array $steps,
    protected readonly string $gate = 'gate',
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function refineDataDefinition(string $name, DataDefinitionInterface $definition, array $values): DataDefinitionInterface {
    $held = $values[$this->gate] ?? NULL;
    $held = is_bool($held) ? ($held ? 'true' : 'false') : (is_scalar($held) ? (string) $held : '');
    foreach ($this->steps[$held] ?? [] as $step) {
      [$operation, $path] = $step;
      if ($operation === 'same') {
        return $definition;
      }
      if ($operation === 'clone') {
        $definition = clone $definition;
        continue;
      }
      $definition = static::at($definition, $path === '' ? [] : explode('.', $path), $operation, $step[2] ?? NULL);
    }
    return $definition;
  }

  /**
   * Takes one step at a place below a definition, on copies.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition.
   * @param list<string> $path
   *   The path below it.
   * @param string $operation
   *   The step.
   * @param mixed $argument
   *   What the step takes.
   *
   * @return \Drupal\Core\TypedData\DataDefinitionInterface
   *   The definition, changed at the place.
   */
  protected static function at(DataDefinitionInterface $definition, array $path, string $operation, mixed $argument): DataDefinitionInterface {
    if (!$definition instanceof DataDefinition) {
      throw new \LogicException('The hand corpus refines only definitions it built.');
    }
    if ($path === []) {
      return match ($operation) {
        'out' => DoesNotApply::marked($definition),
        'choice' => (clone $definition)->addConstraint('Choice', ['choices' => $argument]),
        'req' => (clone $definition)->setRequired(TRUE),
        'label' => (clone $definition)->setLabel($argument),
        'len' => (clone $definition)->addConstraint('Length', ['max' => $argument]),
        'count' => (clone $definition)->addConstraint('Count', ['max' => $argument]),
        default => throw new \LogicException("No step $operation."),
      };
    }
    $property = array_shift($path);
    $copy = clone $definition;
    if ($copy instanceof ListDataDefinition) {
      $copy->setItemDefinition(static::at($copy->getItemDefinition(), $path, $operation, $argument));
      return $copy;
    }
    if (!method_exists($copy, 'setPropertyDefinition') || !method_exists($copy, 'getPropertyDefinition')) {
      throw new \LogicException('The hand corpus steps only into maps and lists.');
    }
    $copy->setPropertyDefinition($property, static::at($copy->getPropertyDefinition($property), $path, $operation, $argument));
    return $copy;
  }

}
