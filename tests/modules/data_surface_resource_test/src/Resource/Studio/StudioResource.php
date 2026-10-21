<?php

declare(strict_types=1);

namespace Drupal\data_surface_resource_test\Resource\Studio;

use Drupal\data_surface\DefinitionMetadata;
use Drupal\data_surface\Resource\Arrangement;
use Drupal\data_surface\Resource\ArrangementGroup;
use Drupal\data_surface\Resource\Attribute\Operation;
use Drupal\data_surface\Resource\Attribute\Resource;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\ResourceInterface;
use Drupal\data_surface_resource_test\Storage\StudioStorage;

/**
 * A studio's settings, arranged: two groups, weights, and input texts.
 *
 * The notes are declared last and weighed first. The hours are a
 * fieldset, the closing time weighed before the opening time inside it;
 * the pricing is a details that starts closed, its rate drawn with a
 * prefix, a suffix and a placeholder. StudioAlter rewords the name.
 *
 * @see \Drupal\data_surface_resource_test\Alter\StudioAlter
 */
#[Resource('resource_test.studio', storage: StudioStorage::class)]
#[Operation('configure', label: 'Configure the studio', permission: 'administer site configuration')]
final class StudioResource implements ResourceInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('name', 'string', t('Studio name'))
      ->setRequired(TRUE)
      ->addConstraint('Length', ['max' => 60]);

    $hours = ArrangementGroup::fieldset('hours', t('Opening hours'));
    $opens = $inputs->add('opens', 'string', t('Opens at'), default: '09:00');
    DefinitionMetadata::setArrangement($opens, new Arrangement(group: $hours, weight: 2));
    $closes = $inputs->add('closes', 'string', t('Closes at'), default: '17:00');
    DefinitionMetadata::setArrangement($closes, new Arrangement(group: $hours, weight: 1));

    $pricing = ArrangementGroup::details('pricing', t('Pricing'), collapsed: TRUE);
    $rate = $inputs->add('rate', 'integer', t('Hourly rate'))
      ->setDescription(t('What an hour in the studio costs.'))
      ->addConstraint('Range', ['min' => 0]);
    DefinitionMetadata::setExamples($rate, [40]);
    DefinitionMetadata::setArrangement($rate, new Arrangement(
      group: $pricing,
      placeholder: t('For example, 40'),
      prefix: '$',
      suffix: t('per hour'),
      help: t('Whole dollars.'),
    ));
    $deposit = $inputs->add('deposit', 'boolean', t('Deposit asked'), default: FALSE);
    DefinitionMetadata::setArrangement($deposit, new Arrangement(group: $pricing));

    $notes = $inputs->add('notes', 'string', t('Notes'))
      ->setSetting('multiline', TRUE);
    DefinitionMetadata::setArrangement($notes, new Arrangement(weight: -10));
  }

}
