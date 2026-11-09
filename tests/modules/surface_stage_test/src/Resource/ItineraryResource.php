<?php

declare(strict_types=1);

namespace Drupal\surface_stage_test\Resource;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\surface\Resource\ArrangementGroup;
use Drupal\surface\Resource\Attribute\Operation;
use Drupal\surface\Resource\Attribute\RefinesInput;
use Drupal\surface\Resource\Attribute\Resource;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\OperationContext;
use Drupal\surface\Resource\ResourceInterface;
use Drupal\surface_stage_test\Storage\ItineraryStorage;

/**
 * A trip, by an id its caller names, drawn over three groups.
 *
 * What a flow is checked against: three groups, `where`, `when` and
 * `who`; a required key with no default in `when`, `day`; and one
 * refinement across groups, the guides `who` offers narrowed by the
 * region `where` chose. Its storage is a key-value collection, as the
 * ledger's, which a transaction rolls back whole. A trip may be
 * canceled, a delete, and closed, an update its operation declares
 * destructive: a confirm page counts each as what cannot be undone.
 */
#[Resource('stage_test.itinerary',
  identity: ['id'],
  storage: ItineraryStorage::class,
  label: new TranslatableMarkup('Itinerary'),
)]
#[Operation('create',
  label: new TranslatableMarkup('Plan a trip'),
  permission: LedgerResource::PERMISSION,
  creates: TRUE,
  idempotent: TRUE,
)]
final class ItineraryResource implements ResourceInterface {

  /**
   * The guides of each region.
   */
  public const GUIDES = [
    'north' => ['ana', 'ben'],
    'south' => ['cy', 'di'],
  ];

  /**
   * Edits a trip, named by its id.
   */
  #[Operation('update', label: new TranslatableMarkup('Edit a trip'), permission: LedgerResource::PERMISSION)]
  public static function update(string $id): OperationContext {
    \Drupal::classResolver(ItineraryStorage::class)->named($id, 'The "update" operation of stage_test.itinerary');
    return new OperationContext(known: ['id' => $id]);
  }

  /**
   * Closes a trip, named by its id: an update that cannot be undone.
   */
  #[Operation('close',
    label: new TranslatableMarkup('Close a trip'),
    permission: LedgerResource::PERMISSION,
    destructive: TRUE,
  )]
  public static function close(string $id): OperationContext {
    \Drupal::classResolver(ItineraryStorage::class)->named($id, 'The "close" operation of stage_test.itinerary');
    return new OperationContext(known: ['id' => $id]);
  }

  /**
   * Cancels a trip, named by its id.
   */
  #[Operation('delete',
    label: new TranslatableMarkup('Cancel a trip'),
    description: new TranslatableMarkup('Cancels a trip.'),
    permission: LedgerResource::PERMISSION,
    destructive: TRUE,
    deletes: TRUE,
  )]
  public static function delete(string $id): OperationContext {
    \Drupal::classResolver(ItineraryStorage::class)->named($id, 'The "delete" operation of stage_test.itinerary');
    return new OperationContext(known: ['id' => $id]);
  }

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $where = ArrangementGroup::fieldset('where', t('Where'));
    $when = ArrangementGroup::fieldset('when', t('When'));
    $who = ArrangementGroup::fieldset('who', t('Who'));

    $inputs->add('id', 'string', t('Id'))
      ->setRequired(TRUE);

    $inputs->add('region', 'string', t('Region'), default: 'north')
      ->setRequired(TRUE)
      ->addConstraint('Choice', ['choices' => array_keys(self::GUIDES)]);
    $inputs->describe('region', group: $where);

    $inputs->add('day', 'string', t('Day'))
      ->setRequired(TRUE);
    $inputs->describe('day', group: $when);

    $inputs->add('nights', 'integer', t('Nights'), default: 1);
    $inputs->describe('nights', group: $when);

    $inputs->add('guide', 'string', t('Guide'))
      ->addConstraint('Choice', ['choices' => array_merge(...array_values(self::GUIDES))]);
    $inputs->describe('guide', group: $who);
  }

  /**
   * A region offers its own guides.
   */
  #[RefinesInput('guide')]
  public static function guides(DataDefinitionInterface $guide, string $region): DataDefinitionInterface {
    return $guide->addConstraint('Choice', ['choices' => self::GUIDES[$region] ?? []]);
  }

}
