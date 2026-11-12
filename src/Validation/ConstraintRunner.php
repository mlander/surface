<?php

declare(strict_types=1);

namespace Drupal\surface\Validation;

use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\TypedDataManagerInterface;
use Drupal\Core\TypedData\Validation\RecursiveContextualValidator;
use Drupal\Core\TypedData\Validation\RecursiveValidator;
use Drupal\surface\DefinitionTypedData;
use Symfony\Component\Validator\ConstraintViolationListInterface;

/**
 * Runs constraint plugins on values, through Typed Data.
 *
 * The rule: this is the one place Surface builds a typed value to run a
 * constraint on a definition a data surface holds.
 * DefinitionTypedData::create() is called from here alone, and no other
 * class takes the typed data manager to validate such a value. Host
 * storage validates its own typed config and entities, and host readers
 * build typed objects to read them; neither goes through here. The class
 * stays when the model moves to `Schema`, because core's constraint
 * plugins expect a typed value: some validators read the context object
 * (ValidKeys, EntityBundleExists, AllowedValues with a callback), and the
 * constraints every definition implies (PrimitiveType, NotNull,
 * ComplexData) read it always.
 *
 * Two questions, one method each:
 * - check(): one constraint plugin, with its options, on one value, and
 *   nothing else: never the constraints of the value's own definition or
 *   of its properties. The typed value is a root named '', so a
 *   violation's property path is the one the constraint gives, '' at the
 *   value itself. The check provider asks this for every `drupal:<id>`
 *   check a document names.
 * - conforms(): a value against a whole definition, every constraint at
 *   every depth, the implied ones included. Outputs (Conforming), a
 *   watched value (WatchedValueCheck) and a default or fixed value from a
 *   site or a route (KeyPaths) ask this.
 *
 * Surface's own seven constraint plugins read the value alone, which
 * ValueOnlyConstraintsTest shows through a plain Symfony validator.
 *
 * Decision: see
 * docs/decisions.md#one-adapter-runs-a-constraint-the-typed-data-footprint-is-pinned.
 *
 * @internal
 */
final class ConstraintRunner {

  use DependencySerializationTrait;

  /**
   * Constructs a ConstraintRunner.
   *
   * @param \Drupal\Core\TypedData\TypedDataManagerInterface $typedDataManager
   *   The typed data manager, which makes the typed value and validates it.
   */
  public function __construct(
    protected TypedDataManagerInterface $typedDataManager,
  ) {
  }

  /**
   * Runs one constraint plugin on one value, alone.
   *
   * @param string $id
   *   The constraint's plugin id.
   * @param mixed $options
   *   Its options, as a definition holds them: an array, NULL for none, or
   *   the one value core's constraint manager reads as `value`.
   * @param mixed $value
   *   The value.
   * @param \Drupal\Core\TypedData\DataDefinitionInterface|null $definition
   *   The definition the typed value is made from, which says how a list or
   *   a map is handed to the validator; NULL for one of type `any`.
   *
   * @return \Symfony\Component\Validator\ConstraintViolationListInterface
   *   What the constraint refused.
   */
  public function check(string $id, mixed $options, mixed $value, ?DataDefinitionInterface $definition = NULL): ConstraintViolationListInterface {
    $constraint = $this->typedDataManager->getValidationConstraintManager()->create($id, $options);
    $typed = DefinitionTypedData::create($this->typedDataManager, $definition ?? DataDefinition::create('any'), $value, '');
    $validator = $this->typedDataManager->getValidator();
    assert($validator instanceof RecursiveValidator);
    $context = $validator->startContext($typed);
    assert($context instanceof RecursiveContextualValidator);
    // Not a root call: the constraint alone, never the definition's own.
    return $context->validate($typed, [$constraint], NULL, FALSE)->getViolations();
  }

  /**
   * Validates a value against a whole definition.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition, as refined for the values being judged.
   * @param mixed $value
   *   The value.
   * @param string|null $name
   *   The root's name, if it has one; a violation's property path starts
   *   below the root either way.
   *
   * @return \Symfony\Component\Validator\ConstraintViolationListInterface
   *   What the definition's constraints refused, at every depth.
   *
   * @throws \InvalidArgumentException
   *   When Typed Data cannot hold the value at all, a string for a map.
   */
  public function conforms(DataDefinitionInterface $definition, mixed $value, ?string $name = NULL): ConstraintViolationListInterface {
    return DefinitionTypedData::create($this->typedDataManager, $definition, $value, $name)->validate();
  }

}
