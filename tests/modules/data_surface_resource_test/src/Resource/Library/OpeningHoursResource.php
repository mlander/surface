<?php

declare(strict_types=1);

namespace Drupal\data_surface_resource_test\Resource\Library;

use Drupal\Core\TypedData\DataDefinition;
use Drupal\data_surface\Refinement\Condition;
use Drupal\data_surface\Resource\Attribute\Operation;
use Drupal\data_surface\Resource\Attribute\Resource;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\OperationContext;
use Drupal\data_surface\Resource\ResourceInterface;
use Drupal\data_surface_resource_test\Storage\OpeningHoursStorage;

/**
 * A library's opening hours: the common settings recipes, each a key.
 *
 * Its config schema is fully validatable, so a key its schema does not
 * call nullable is always held: the holidays choice offers no empty
 * option, and null is refused for it naming its default
 * (docs/decisions.md#a-key-its-storage-always-holds).
 *
 * The recipes page, docs/recipes.md, quotes these keys, and
 * QuotedCodeDocsTest fails when the page and this class differ;
 * RecipesTest holds each recipe to what the page says it does.
 */
#[Resource('resource_test.opening_hours', storage: OpeningHoursStorage::class)]
final class OpeningHoursResource implements ResourceInterface {

  /**
   * Configures the opening hours, which always exist.
   */
  #[Operation('configure', label: 'Configure the opening hours', permission: 'administer site configuration')]
  public static function configure(): OperationContext {
    return new OperationContext('configure');
  }

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('opens', 'string', t('Opens at'), default: '09:00')
      ->setRequired(TRUE)
      ->setDescription(t('HH:MM, on a 24-hour clock.'))
      ->setSetting('widget', 'time')
      ->addConstraint('Regex', [
        'pattern' => '/^([01]\d|2[0-3]):[0-5]\d$/D',
        'message' => t('Write the time as HH:MM, such as 09:00.'),
      ]);

    $inputs->add('closes', 'integer', t('Closes at, in minutes after midnight'), default: 1020)
      ->setRequired(TRUE)
      ->setDescription(t('Later than noon: 1020 is 17:00.'))
      ->addConstraint('Range', ['min' => 721, 'max' => 1439]);

    $day = DataDefinition::create('string')
      ->addConstraint('LabeledChoice', [
        'choices' => [
          'mon' => t('Monday'),
          'tue' => t('Tuesday'),
          'wed' => t('Wednesday'),
          'thu' => t('Thursday'),
          'fri' => t('Friday'),
          'sat' => t('Saturday'),
          'sun' => t('Sunday'),
        ],
      ]);
    $inputs->addList('days', $day, t('Days open'), default: ['mon', 'tue', 'wed', 'thu', 'fri'])
      ->setRequired(TRUE)
      ->addConstraint('UniqueItems', []);

    $inputs->add('notes', 'string', t('Notes'))
      ->setSetting('multiline', TRUE);

    $inputs->add('after_hours', 'boolean', t('After-hours access'), default: FALSE);
    $inputs->add('door_code', 'string', t('Door code'))
      ->setRequired(TRUE)
      ->addConstraint('Length', ['min' => 4, 'max' => 12]);
    $inputs->narrow('door_code')
      ->unless(Condition::equals('after_hours', TRUE))
      ->doesNotApply();

    $inputs->addList('late_days', $day, t('After-hours days'), default: [])
      ->setRequired(TRUE)
      ->addConstraint('Count', [
        'min' => 1,
        'minMessage' => t('Choose at least one day for after-hours access.'),
      ]);
    $inputs->narrow('late_days')
      ->unless(Condition::equals('after_hours', TRUE))
      ->doesNotApply();

    $inputs->addList('keywords', 'string', t('Keywords'), default: []);

    $inputs->add('holidays', 'string', t('On public holidays'), default: 'closed')
      ->addConstraint('LabeledChoice', [
        'choices' => [
          'closed' => t('Closed'),
          'reduced' => t('Reduced hours'),
          'open' => t('Open as usual'),
        ],
      ]);
  }

}
