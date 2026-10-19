<?php

declare(strict_types=1);

namespace Drupal\data_surface_resource_test\Resource\Exhibit;

use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\data_surface\Resource\Attribute\Operation;
use Drupal\data_surface\Resource\Attribute\RefinesInput;
use Drupal\data_surface\Resource\Attribute\Resource;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\OperationContext;
use Drupal\data_surface\Resource\ResourceInterface;
use Drupal\data_surface_resource_test\Storage\ExhibitStorage;
use Drupal\data_surface_resource_test\Structure\Exhibit\LoanStructure;
use Drupal\data_surface_resource_test\Structure\Exhibit\ProvenanceStructure;

/**
 * An exhibit: access of both kinds, on keys, an attached child and a mixin.
 *
 * The provenance is mixed in, so its access answers for two of the
 * exhibit's own keys; the loan is attached, its access answering for the
 * loan, and the loan's insurance inside it answering for itself. Who may
 * see or change them is access (ExhibitAccess): static for an inspector
 * and an archivist, runtime for anyone else. Which rooms an account may
 * choose is not access: it narrows the values a key allows, by account,
 * and so it is a runtime refiner.
 *
 * @see docs/resources.md#access
 */
#[Resource('resource_test.exhibit', storage: ExhibitStorage::class)]
final class ExhibitResource implements ResourceInterface {

  /**
   * The rooms, and the one only some accounts may choose.
   */
  public const ROOMS = ['east' => 'East hall', 'west' => 'West hall', 'vault' => 'The vault'];

  /**
   * Configures the exhibit, which always exists.
   */
  #[Operation('configure', label: 'Configure the exhibit', permission: 'keep an exhibit')]
  public static function configure(): OperationContext {
    return new OperationContext('configure');
  }

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('title', 'string', t('Title'))
      ->setRequired(TRUE);
    $inputs->add('status', 'string', t('Status'), default: 'open')
      ->setRequired(TRUE)
      ->addConstraint('LabeledChoice', [
        'choices' => [
          'open' => t('Open'),
          'sealed' => t('Sealed'),
        ],
      ]);
    $inputs->add('room', 'string', t('Room'), default: 'east')
      ->addConstraint('LabeledChoice', ['choices' => self::ROOMS]);
    $inputs->with(ProvenanceStructure::class);
    $inputs->attach('loan', LoanStructure::class)
      ->setLabel(t('Loan'));
  }

  /**
   * The vault is only for an account that may use it.
   *
   * Narrowing what a key allows by account is a runtime refiner, not
   * access: access answers whether the key may be changed, seen, or
   * neither, never which of its values. It reads the current user, which
   * no schema can state, so it says runtime and the full schema keeps the
   * vault, marked `runtime`.
   */
  #[RefinesInput('room', runtime: TRUE)]
  public static function roomsForAccount(DataDefinitionInterface $room): DataDefinitionInterface {
    if (\Drupal::currentUser()->hasPermission('use the vault')) {
      return $room;
    }
    return $room->addConstraint('LabeledChoice', ['choices' => array_diff_key(self::ROOMS, ['vault' => TRUE])]);
  }

}
