<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Resource\Studio;

use Drupal\surface\Resource\ArrangementGroup;
use Drupal\surface\Resource\Attribute\Operation;
use Drupal\surface\Resource\Attribute\Resource;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\ResourceInterface;
use Drupal\surface_resource_test\Storage\StudioStorage;
use PortableSchema\Builder\Schema;

/**
 * A studio's settings, arranged: two groups, weights, and input texts.
 *
 * The notes are declared last and weighed first. The hours are a
 * fieldset, the closing time weighed before the opening time inside it;
 * the pricing is a details that starts closed, its rate drawn with a
 * prefix, a suffix and a placeholder. StudioAlter rewords the name.
 *
 * @see \Drupal\surface_resource_test\Alter\StudioAlter
 */
#[Resource('resource_test.studio', storage: StudioStorage::class)]
#[Operation('configure', label: 'Configure the studio', permission: 'administer site configuration')]
final class StudioResource implements ResourceInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('name', Schema::string()
      ->title(t('Studio name'))
      ->maxLength(60)
      ->required());

    $hours = ArrangementGroup::fieldset('hours', t('Opening hours'));
    $inputs->add('opens', Schema::string()
      ->title(t('Opens at'))
      ->default('09:00'));
    $inputs->describe('opens', group: $hours, weight: 2);
    $inputs->add('closes', Schema::string()
      ->title(t('Closes at'))
      ->default('17:00'));
    $inputs->describe('closes', group: $hours, weight: 1);

    $pricing = ArrangementGroup::details('pricing', t('Pricing'), collapsed: TRUE);
    $inputs->add('rate', Schema::integer()
      ->title(t('Hourly rate'))
      ->description(t('What an hour in the studio costs.'))
      ->minimum(0)
      ->examples([40]));
    $inputs->describe('rate',
      group: $pricing,
      placeholder: t('For example, 40'),
      prefix: '$',
      suffix: t('per hour'),
      help: t('Whole dollars.'),
    );
    $inputs->add('deposit', Schema::bool()
      ->title(t('Deposit asked'))
      ->default(FALSE));
    $inputs->describe('deposit', group: $pricing);

    $inputs->add('notes', Schema::string()
      ->title(t('Notes'))
      ->ui(widget: 'textarea'));
    $inputs->describe('notes', weight: -10);
  }

}
