<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Structure\Sender;

use Drupal\surface\Refinement\Condition;
use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;
use PortableSchema\Builder\Schema;

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
    $inputs->add('name', Schema::string()
      ->title(t('Name'))
      ->maxLength(64));
    $inputs->add('email', Schema::string()->format('email')
      ->title(t('Email')));
    $inputs->add('signature', Schema::string()
      ->title(t('Signature')));
    $inputs->narrow('name')
      ->when(Condition::present('email'))
      ->require()
      ->because(t('Required once an email is given.'));
    $inputs->narrow('signature')
      ->when(Condition::absent('email'))
      ->doesNotApply();
  }

}
