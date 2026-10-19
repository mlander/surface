<?php

declare(strict_types=1);

namespace Drupal\data_surface_resource_test\Structure\Sender;

use Drupal\data_surface\Refinement\Condition;
use Drupal\data_surface\Resource\Attribute\Structure;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\StructureInterface;

/**
 * Who a message is from: a name, required once an email is set.
 *
 * The third fresh-eyes run's sender (docs/fresh-eyes-2026-10-19b.md,
 * entry 22): a rule whose condition asks only whether the email holds a
 * value, so its refusal says "when Email is set", never the address.
 *
 * @see docs/decisions.md#a-refusal-names-what-it-depends-on
 */
#[Structure('resource_test.sender_identity')]
final class SenderIdentityStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('name', 'string', t('Name'))
      ->addConstraint('Length', ['max' => 64]);
    $inputs->add('email', 'email', t('Email'));
    $inputs->add('signature', 'string', t('Signature'));
    $inputs->narrow('name')
      ->when(Condition::present('email'))
      ->require()
      ->describe(t('Required once an email is given.'));
    $inputs->narrow('signature')
      ->when(Condition::absent('email'))
      ->doesNotApply();
  }

}
