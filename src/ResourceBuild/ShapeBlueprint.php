<?php

declare(strict_types=1);

namespace Drupal\data_surface\ResourceBuild;

use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\ListDataDefinition;
use Drupal\Core\TypedData\MapDataDefinition;
use Drupal\data_surface\DataSurface;
use Drupal\data_surface\Refinement\ChoiceSet;
use Drupal\data_surface\Refinement\Narrowing;
use Drupal\data_surface\Refinement\Rule;
use Drupal\data_surface\Resource\BlueprintAdditionsInterface;

/**
 * What an alter of a shape is handed: the shape, to tighten.
 *
 * A shape is one value, so an alter of it says two things of that value,
 * named by the shape's id: narrow() with no condition, a constraint
 * tightened or a pattern where it has none, and describe(), its label or
 * description. Both apply to every key that uses the shape, on the whole
 * site. Everything that adds a key, a child or a value is refused naming
 * the verb, since a shape has no keys to add to and only narrows.
 *
 * Decision: see docs/decisions.md#a-shape-is-a-named-definition.
 *
 * @internal
 */
final class ShapeBlueprint implements BlueprintAdditionsInterface {

  use DeprecatedWithTrait;

  /**
   * The rules declared, in order.
   *
   * @var \Drupal\data_surface\Refinement\Rule[]
   */
  protected array $rules = [];

  /**
   * The label described, if any.
   */
  protected string|\Stringable|null $label = NULL;

  /**
   * The description described, if any.
   */
  protected string|\Stringable|null $description = NULL;

  /**
   * Constructs the blueprint.
   *
   * @param string $shape
   *   The shape's id.
   * @param string $alter
   *   The alter's class, for the messages.
   */
  public function __construct(
    protected readonly string $shape,
    protected readonly string $alter,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function add(string $key, string $type, string|\Stringable $label, mixed $default = NULL): DataDefinition {
    throw $this->refused(sprintf('add("%s")', $key));
  }

  /**
   * {@inheritdoc}
   */
  public function addDefinition(string $key, DataDefinitionInterface $definition, mixed $default = NULL): DataDefinitionInterface {
    throw $this->refused(sprintf('addDefinition("%s")', $key));
  }

  /**
   * {@inheritdoc}
   */
  public function attach(string $key, string $child, array $reads = []): MapDataDefinition {
    throw $this->refused(sprintf('attach("%s")', $key));
  }

  /**
   * {@inheritdoc}
   */
  public function attachList(string $key, string $child, ?int $min = NULL, ?int $max = NULL): ListDataDefinition {
    throw $this->refused(sprintf('attachList("%s")', $key));
  }

  /**
   * {@inheritdoc}
   */
  public function mixIn(string $structure, array $reads = []): static {
    throw $this->refused('mixIn()');
  }

  /**
   * {@inheritdoc}
   */
  public function extendChoices(string $key, array $choices): static {
    throw new \LogicException(sprintf('%s alters the shape %s and calls extendChoices(): a shape only narrows, at every key that uses it, so nothing offers more values on it.', $this->alter, $this->shape));
  }

  /**
   * {@inheritdoc}
   *
   * The shape itself, by its id: its label and description at every key
   * that leaves them as the shape says.
   */
  public function describe(string $key, string|\Stringable|null $label = NULL, string|\Stringable|null $description = NULL, ?string $after = NULL): static {
    $this->assertNamed('describe', $key);
    if ($after !== NULL) {
      throw new \LogicException(sprintf('%s alters the shape %s and places it after "%s": a shape is a value, drawn where each key that uses it is.', $this->alter, $this->shape, $after));
    }
    $this->label = $label ?? $this->label;
    $this->description = $description ?? $this->description;
    return $this;
  }

  /**
   * {@inheritdoc}
   *
   * The shape itself, by its id, with no condition: a shape has no
   * siblings for one to read.
   */
  public function narrow(string $key): Rule {
    $this->assertNamed('narrow', $key);
    return $this->rules[] = new Rule($key);
  }

  /**
   * Applies what the alter said to the shape, refusing what widens it.
   *
   * @param \Drupal\Core\TypedData\DataDefinition $definition
   *   The shape, as declared and as the alters before this one left it.
   *
   * @return \Drupal\Core\TypedData\DataDefinition
   *   The shape, tightened.
   *
   * @throws \LogicException
   *   For a rule with a condition, or one that requires or rules out, and
   *   for an effect that would widen the shape.
   */
  public function applyTo(DataDefinition $definition): DataDefinition {
    foreach ($this->rules as $rule) {
      $problem = match (TRUE) {
        $rule->condition() !== NULL => 'with a condition, and a shape has no siblings for one to read: its rule holds wherever it is used',
        $rule->requires() => 'that requires it, and whether a value must be given is each key\'s',
        $rule->rulesOut() => 'that rules it out, and a value that does not apply is each key\'s to say',
        default => NULL,
      };
      if ($problem !== NULL) {
        throw new \LogicException(sprintf('%s narrows the shape %s %s.', $this->alter, $this->shape, $problem));
      }
      foreach (array_keys($rule->constraints()) as $constraint) {
        $fits = match ($constraint) {
          'Range' => in_array($definition->getDataType(), ['integer', 'float'], TRUE),
          'Count' => FALSE,
          'Choice' => ChoiceSet::of($definition) !== NULL,
          default => TRUE,
        };
        if (!$fits) {
          throw new \LogicException(sprintf('%s narrows the shape %s, whose type is %s, by %s, which it cannot take.', $this->alter, $this->shape, $definition->getDataType(), $constraint === 'Choice' ? 'a list of its values' : $constraint));
        }
      }
      $written = $rule->applyAsWritten(DataSurface::deepClone($definition));
      $widened = Narrowing::narrowed($written, $definition);
      if ($widened !== NULL) {
        throw new \LogicException(sprintf('%s widens the shape %s: %s. An alter of a shape narrows it at every key that uses it, so it may only tighten.', $this->alter, $this->shape, $widened));
      }
      $narrowed = $rule->apply($definition);
      assert($narrowed instanceof DataDefinition);
      $definition = $narrowed;
    }
    if ($this->label !== NULL) {
      $definition->setLabel($this->label);
    }
    if ($this->description !== NULL) {
      $definition->setDescription($this->description);
    }
    return $definition;
  }

  /**
   * Refuses a name other than the shape's own id.
   *
   * @param string $verb
   *   The method, for the message.
   * @param string $key
   *   What it was handed.
   *
   * @throws \LogicException
   *   When it is not the shape's id.
   */
  protected function assertNamed(string $verb, string $key): void {
    if ($key !== $this->shape) {
      throw new \LogicException(sprintf('%s alters the shape %s and calls %s("%s"): an alter of a shape names the shape itself, %s("%s").', $this->alter, $this->shape, $verb, $key, $verb, $this->shape));
    }
  }

  /**
   * Builds the refusal of a verb that adds to a shape.
   *
   * @param string $call
   *   The call, for the message.
   *
   * @return \LogicException
   *   The refusal.
   */
  protected function refused(string $call): \LogicException {
    return new \LogicException(sprintf(
      '%s alters the shape %s and calls %s. A shape is one value, with no keys to mount and nothing to attach: an alter of it tightens it with narrow("%s") and rewords it with describe("%s"), at every key that uses it.',
      $this->alter,
      $this->shape,
      $call,
      $this->shape,
      $this->shape,
    ));
  }

}
