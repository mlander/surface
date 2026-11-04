<?php

declare(strict_types=1);

namespace Drupal\surface\Resource;

use Drupal\surface\ResourceBuild\PropertyDeclaration;

/**
 * Supplies defineInputs() and defineOutputs() from the class's properties.
 *
 * The second spelling of a resource's or structure's keys: each key a
 * property carrying the attribute of the verb it stands for, #[Input] for
 * add(), #[Attach] for attach() and so on (src/Resource/Attribute). What
 * PHP says of the property is read as the key: its name, its type, its
 * initializer as the default, and whether it must be given. The build
 * calls defineInputs() as it does on every class, and the trait answers
 * by calling the same blueprint verbs the method would, in the order the
 * properties are written; the engine, alters, refiners, operations and
 * access are as they are for any class.
 *
 * A class uses either spelling, never both: one that needs what no
 * attribute spells (a list computed by a call, a key declared in a loop,
 * a condition #[Rule] does not spell) keeps defineInputs().
 *
 * @code
 * #[Structure('event.person')]
 * final class PersonStructure implements StructureInterface {
 *
 *   use DeclaresByProperties;
 *
 *   #[Port]
 *   public bool $listed;
 *
 *   #[Input('Name')]
 *   public string $name;
 *
 *   #[Input('Email', type: 'email')]
 *   #[Rule(whenEquals: ['listed', TRUE], require: TRUE)]
 *   public ?string $email = NULL;
 *
 * }
 * @endcode
 *
 * Decision: see docs/decisions.md#a-class-may-declare-its-keys-as-properties.
 *
 * @see docs/declaring-a-resource.md#declared-as-properties
 */
trait DeclaresByProperties {

  /**
   * Declares the inputs: every property but those marked #[Output].
   *
   * @param \Drupal\surface\Resource\BlueprintInterface $inputs
   *   The input blueprint to fill.
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    PropertyDeclaration::inputs(static::class, $inputs);
  }

  /**
   * Declares the outputs: the properties marked #[Output].
   *
   * Read only where the class implements DefinesOutputsInterface.
   *
   * @param \Drupal\surface\Resource\BlueprintInterface $outputs
   *   The output blueprint to fill.
   */
  public static function defineOutputs(BlueprintInterface $outputs): void {
    PropertyDeclaration::outputs(static::class, $outputs);
  }

}
