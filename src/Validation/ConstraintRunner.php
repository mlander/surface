<?php

declare(strict_types=1);

namespace Drupal\surface\Validation;

use Drupal\Core\DependencyInjection\ClassResolverInterface;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\TypedDataManagerInterface;
use Drupal\Core\TypedData\Validation\RecursiveContextualValidator;
use Drupal\Core\TypedData\Validation\RecursiveValidator;
use Drupal\Core\Validation\BasicRecursiveValidatorFactory;
use Drupal\surface\DefinitionTypedData;
use Drupal\surface\Plugin\Validation\Constraint\JsonSchemaConstraint;
use Drupal\surface\Plugin\Validation\Constraint\LabeledChoiceConstraint;
use Drupal\surface\Plugin\Validation\Constraint\LanguageExistsConstraint;
use Drupal\surface\Plugin\Validation\Constraint\LinkUriConstraint;
use Drupal\surface\Plugin\Validation\Constraint\ReferenceableTypeConstraint;
use Drupal\surface\Plugin\Validation\Constraint\UniqueConstraint;
use Drupal\surface\Plugin\Validation\Constraint\UniqueItemsConstraint;
use Drupal\surface\Plugin\Validation\Constraint\ValidReferenceConstraint;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

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
 * One question:
 * - check(): one constraint plugin, with its options, on one value, and
 *   nothing else: never the constraints of the value's own definition or
 *   of its properties. Surface's own plugins (VALUE_ONLY) run on the
 *   plain value, through a plain validator (plainly()), but only while
 *   the constraint manager's definition of the id names the class Surface
 *   ships for it: a plugin whose class another module altered may read
 *   the typed context, so it runs as every other plugin does, on a typed
 *   value (typed()), a root named '', so a violation's
 *   property path is the one the constraint gives, '' at the value
 *   itself, on either path. The check provider asks this for every
 *   `drupal:<id>` check a document names. typed() is public for one
 *   reader, ValueOnlyConstraintsTest, which holds the two paths to the
 *   same violations.
 * A whole value is never held here: inputs, outputs, a watched value and
 * a site's or a route's value (KeyPaths) are held to their schema by the
 * dialect's validator, which asks check() for each `drupal:` check.
 *
 * Surface's own eight constraint plugins read the value alone, which
 * ValueOnlyConstraintsTest shows, so they run without a typed value.
 *
 * Decision: see
 * docs/decisions.md#one-adapter-runs-a-constraint-the-typed-data-footprint-is-pinned.
 *
 * @internal
 */
final class ConstraintRunner {

  use DependencySerializationTrait {
    __sleep as protected sleepServices;
  }

  /**
   * Surface's own constraint plugins, which read the value alone.
   *
   * By plugin id, the class Surface ships for it: the plain path is taken
   * only while the constraint manager's definition of the id names that
   * class. ValueOnlyConstraintsTest holds each to the violations a typed
   * value gives, and fails when the module ships a plugin this does not
   * list.
   */
  public const VALUE_ONLY = [
    'JsonSchema' => JsonSchemaConstraint::class,
    'LabeledChoice' => LabeledChoiceConstraint::class,
    'LanguageExists' => LanguageExistsConstraint::class,
    'SurfaceLinkUri' => LinkUriConstraint::class,
    'SurfaceReferenceableType' => ReferenceableTypeConstraint::class,
    'SurfaceUnique' => UniqueConstraint::class,
    'SurfaceValidReference' => ValidReferenceConstraint::class,
    'UniqueItems' => UniqueItemsConstraint::class,
  ];

  /**
   * The plain validator, made once (plainly()).
   */
  protected ?ValidatorInterface $plain = NULL;

  /**
   * Constructs a ConstraintRunner.
   *
   * @param \Drupal\Core\TypedData\TypedDataManagerInterface $typedDataManager
   *   The typed data manager, which makes the typed value and validates it.
   * @param \Drupal\Core\DependencyInjection\ClassResolverInterface $classResolver
   *   The class resolver, which makes a constraint's validator for the
   *   plain validator, so its services resolve.
   */
  public function __construct(
    protected TypedDataManagerInterface $typedDataManager,
    protected ClassResolverInterface $classResolver,
  ) {
  }

  /**
   * {@inheritdoc}
   *
   * The plain validator is made again where it is needed.
   */
  public function __sleep(): array {
    return array_values(array_diff($this->sleepServices(), ['plain']));
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
    return $this->readsValueAlone($id) ? $this->plainly($id, $options, $value) : $this->typed($id, $options, $value, $definition);
  }

  /**
   * Answers whether a plugin id runs on the plain value.
   *
   * @param string $id
   *   The constraint's plugin id.
   *
   * @return bool
   *   TRUE where VALUE_ONLY lists the id and the constraint manager's
   *   definition of it names the class VALUE_ONLY gives.
   */
  protected function readsValueAlone(string $id): bool {
    if (!isset(self::VALUE_ONLY[$id])) {
      return FALSE;
    }
    $definition = $this->typedDataManager->getValidationConstraintManager()->getDefinition($id, FALSE);
    return is_array($definition) && ($definition['class'] ?? NULL) === self::VALUE_ONLY[$id];
  }

  /**
   * Runs one constraint plugin on one value, through a typed value.
   *
   * The path check() takes for every plugin but VALUE_ONLY's. It exists
   * for the two-path test alone, ValueOnlyConstraintsTest, which holds the
   * plain path to the violations this path gives.
   *
   * @param string $id
   *   The constraint's plugin id.
   * @param mixed $options
   *   Its options, as a definition holds them.
   * @param mixed $value
   *   The value.
   * @param \Drupal\Core\TypedData\DataDefinitionInterface|null $definition
   *   The definition the typed value is made from; NULL for one of type
   *   `any`.
   *
   * @return \Symfony\Component\Validator\ConstraintViolationListInterface
   *   What the constraint refused.
   *
   * @internal
   */
  public function typed(string $id, mixed $options, mixed $value, ?DataDefinitionInterface $definition = NULL): ConstraintViolationListInterface {
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
   * Runs one of Surface's own constraint plugins on the plain value.
   *
   * Two rules make a plain validator say what Typed Data's says. It words
   * messages with core's DrupalTranslator, which turns a `{{ name }}`
   * parameter into `%name` and gives a TranslatableMarkup, as core's
   * BasicRecursiveValidatorFactory builds it (Symfony's recursive
   * validator, DrupalTranslator, Drupal's constraint validator factory,
   * no typed data manager); Symfony's own builder cannot take Drupal's
   * translator. And it runs the constraint in the constraint's own
   * groups, so a constraint runs whatever its groups, as on a typed
   * value, which Typed Data validates with no group.
   *
   * @param string $id
   *   The constraint's plugin id, one VALUE_ONLY lists with its class.
   * @param mixed $options
   *   Its options, as a definition holds them.
   * @param mixed $value
   *   The value.
   *
   * @return \Symfony\Component\Validator\ConstraintViolationListInterface
   *   What the constraint refused.
   */
  protected function plainly(string $id, mixed $options, mixed $value): ConstraintViolationListInterface {
    $constraint = $this->typedDataManager->getValidationConstraintManager()->create($id, $options);
    $this->plain ??= (new BasicRecursiveValidatorFactory($this->classResolver))->createValidator();
    return $this->plain->validate($value, $constraint, $constraint->groups);
  }

}
