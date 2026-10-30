<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Structure\Mixin;

use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\surface\Refinement\Condition;
use Drupal\surface\Refinement\DoesNotApply;
use Drupal\surface\Resource\Attribute\RefinesInput;
use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;
use Drupal\surface_resource_test\Access\OwnershipAccess;

/**
 * Who owns a thing, and whether its editors share it: mixed in, with a port.
 *
 * It reads one value of its owner, the audience the thing is for, which
 * whatever mixes it in wires to a key of its own: the journal's
 * visibility, the archive's circle. Shared does not apply to a public
 * thing (a coded refiner of the port, enumerated where it is wired); a
 * staff owner's name is short (a rule declared on the port). Its access
 * class locks or omits its members, by permission.
 *
 * @see docs/resources.md#mixins
 */
#[Structure('resource_test.ownership', access: OwnershipAccess::class)]
final class OwnershipStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->reads('audience', 'string', required: TRUE);
    $inputs->add('owner', 'string', t('Owner'))
      ->setRequired(TRUE);
    $inputs->add('shared', 'boolean', t('Shared with editors'), default: FALSE);
    $inputs->narrow('owner')
      ->when(Condition::equals('audience', 'staff'))
      ->constrain('Length', ['max' => 32])
      ->because(t('Up to 32 characters for a staff audience.'));
  }

  /**
   * A public thing is shared with everyone already.
   */
  #[RefinesInput('shared')]
  public static function sharedInsideOnly(DataDefinitionInterface $shared, string $audience): DataDefinitionInterface|DoesNotApply {
    return $audience === 'public' ? DoesNotApply::here() : $shared;
  }

}
