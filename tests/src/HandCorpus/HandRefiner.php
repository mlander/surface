<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\HandCorpus;

use Drupal\surface\Contract\DefinitionSchema;
use Drupal\surface\Contract\NodeFacts;
use Drupal\surface\Contract\PortableKeywords;
use Drupal\surface\Refinement\Answer;
use Drupal\surface\Refinement\ChoiceSet;
use Drupal\surface\Refinement\Narrowing;
use Drupal\surface\SurfaceRefinerInterface;
use PortableSchema\Builder\Schema;

/**
 * Refines a key by what one gate key holds, for the hand corpus.
 *
 * For each value of the gate, a list of steps, each an operation and the
 * dotted path below the key it applies at ('' for the key itself): `out`
 * rules the place out, `choice` answers a Choice of the choices given,
 * `req` requires it, `label` gives it the title given, `len` a Length and
 * `count` a Count of the maximum given, each an answer at its place;
 * `clone` answers nothing, and `same` ends the steps. A list's item is
 * the place below a list, whatever its path says.
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
  public function refine(string $name, Schema $key, array $values, string $who): array {
    $held = $values[$this->gate] ?? NULL;
    $held = is_bool($held) ? ($held ? 'true' : 'false') : (is_scalar($held) ? (string) $held : '');
    $answers = [];
    $schema = $key;
    foreach ($this->steps[$held] ?? [] as $step) {
      [$operation, $path] = $step;
      if ($operation === 'same') {
        break;
      }
      if ($operation === 'clone') {
        continue;
      }
      $parents = $path === '' ? [] : explode('.', $path);
      // A bound written over the key's, as the step wrote it on a copy of
      // the definition: one that would widen it is refused, in the words
      // of the narrowing check.
      $widened = static::widened($schema, $parents, $operation, $step[2] ?? NULL);
      if ($widened !== NULL) {
        throw new \LogicException(sprintf(Answer::WIDENED, $name, $who, $widened));
      }
      $answer = static::at($schema, $parents, $operation, $step[2] ?? NULL);
      $answers[] = $answer;
      $schema = Answer::apply($schema, $answer, $name, $who, TRUE);
    }
    return $answers;
  }

  /**
   * Gives one step's answer at a place below a key.
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   The key's schema there, as it stands.
   * @param list<string> $path
   *   The path below it.
   * @param string $operation
   *   The step.
   * @param mixed $argument
   *   What the step takes.
   *
   * @return \PortableSchema\Builder\Schema
   *   The answer for the place, placed at the path.
   */
  protected static function at(Schema $schema, array $path, string $operation, mixed $argument): Schema {
    if ($path === []) {
      return match ($operation) {
        'out' => Schema::boolean(FALSE),
        'choice' => static::choice($schema, (array) $argument),
        'req' => Schema::any()->required(),
        'label' => Schema::any()->with('title', $argument),
        'len' => Schema::any()->with(PortableKeywords::CHECK, ['drupal:Length' => ['max' => $argument]]),
        'count' => Schema::any()->with(PortableKeywords::CHECK, ['drupal:Count' => ['max' => $argument]]),
        default => throw new \LogicException("No step $operation."),
      };
    }
    $property = (string) array_shift($path);
    if (DefinitionSchema::isList($schema)) {
      $item = DefinitionSchema::itemNode($schema);
      if ($item === NULL) {
        throw new \LogicException('The hand corpus steps only into maps and lists.');
      }
      return Schema::any()->with('items', static::at($item, $path, $operation, $argument));
    }
    $inner = DefinitionSchema::propertyNode($schema, $property);
    if ($inner === NULL) {
      throw new \LogicException('The hand corpus steps only into maps and lists.');
    }
    return Schema::any()->with('properties', [$property => static::at($inner, $path, $operation, $argument)]);
  }

  /**
   * Says what a bound step written over the key's bound would widen.
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   The key's schema there.
   * @param list<string> $path
   *   The path below it.
   * @param string $operation
   *   The step.
   * @param mixed $argument
   *   What the step takes.
   *
   * @return string|null
   *   What widens, as Narrowing says it, or NULL.
   */
  protected static function widened(Schema $schema, array $path, string $operation, mixed $argument): ?string {
    if ($path === []) {
      $constraint = ['len' => 'Length', 'count' => 'Count'][$operation] ?? NULL;
      $held = $constraint === NULL ? NULL : NodeFacts::constraints($schema)[$constraint] ?? NULL;
      return $held === NULL ? NULL : Narrowing::constraintWidened($constraint, (array) $held, ['max' => $argument]);
    }
    $property = (string) array_shift($path);
    if (DefinitionSchema::isList($schema)) {
      $item = DefinitionSchema::itemNode($schema);
      $widened = $item === NULL ? NULL : static::widened($item, $path, $operation, $argument);
      return $widened === NULL ? NULL : 'inside the list items, ' . $widened;
    }
    $inner = DefinitionSchema::propertyNode($schema, $property);
    $widened = $inner === NULL ? NULL : static::widened($inner, $path, $operation, $argument);
    return $widened === NULL ? NULL : sprintf('inside the %s property, %s', $property, $widened);
  }

  /**
   * Says a Choice of the values given as an answer.
   *
   * A Choice where the key holds none; the key's Choice narrowed (`enum`
   * where the values keep its order, `options()` where they do not).
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   The key's schema.
   * @param array $choices
   *   The values.
   *
   * @return \PortableSchema\Builder\Schema
   *   The answer.
   */
  protected static function choice(Schema $schema, array $choices): Schema {
    $choices = array_values($choices);
    $set = ChoiceSet::ofSchema($schema, 'Choice');
    if ($set === NULL || ChoiceSet::ofSchema($schema)?->constraint !== 'Choice') {
      return Schema::any()->with(PortableKeywords::CHECK, ['drupal:Choice' => ['choices' => $choices]]);
    }
    $kept = array_values(array_filter($set->values, static fn (mixed $value): bool => in_array($value, $choices, TRUE)));
    if ($kept === $choices) {
      return Schema::enum($choices);
    }
    return Schema::oneOf(array_map(static fn (mixed $value): Schema => Schema::const($value), $choices));
  }

}
