<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Resource\Library;

use Drupal\surface\Refinement\Condition;
use Drupal\surface\Resource\Attribute\Operation;
use Drupal\surface\Resource\Attribute\Resource;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\OperationContext;
use Drupal\surface\Resource\ResourceInterface;
use Drupal\surface_resource_test\Storage\OpeningHoursStorage;
use PortableSchema\Builder\Schema;

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
    $inputs->addSchema('opens', Schema::string()
      ->title(t('Opens at'))
      ->description(t('HH:MM, on a 24-hour clock.'))
      ->default('09:00')
      ->check('drupal:Regex', [
        'pattern' => '/^([01]\d|2[0-3]):[0-5]\d$/D',
        'message' => t('Write the time as HH:MM, such as 09:00.'),
      ])
      ->ui(widget: 'time')
      ->required());

    $inputs->addSchema('closes', Schema::integer()
      ->title(t('Closes at, in minutes after midnight'))
      ->description(t('Later than noon: 1020 is 17:00.'))
      ->default(1020)
      ->minimum(721)
      ->maximum(1439)
      ->required());

    $day = Schema::options([
      'mon' => t('Monday'),
      'tue' => t('Tuesday'),
      'wed' => t('Wednesday'),
      'thu' => t('Thursday'),
      'fri' => t('Friday'),
      'sat' => t('Saturday'),
      'sun' => t('Sunday'),
    ]);
    $inputs->addSchema('days', Schema::list($day)
      ->title(t('Days open'))
      ->default(['mon', 'tue', 'wed', 'thu', 'fri'])
      ->uniqueItems(TRUE)
      ->required());

    $inputs->addSchema('notes', Schema::string()
      ->title(t('Notes'))
      ->ui(widget: 'textarea'));

    $inputs->addSchema('after_hours', Schema::bool()
      ->title(t('After-hours access'))
      ->default(FALSE));
    $inputs->addSchema('door_code', Schema::string()
      ->title(t('Door code'))
      ->minLength(4)
      ->maxLength(12)
      ->required());
    $inputs->narrow('door_code')
      ->unless(Condition::equals('after_hours', TRUE))
      ->doesNotApply();

    $inputs->addSchema('late_days', Schema::list($day)
      ->title(t('After-hours days'))
      ->default([])
      ->check('drupal:Count', [
        'min' => 1,
        'minMessage' => t('Choose at least one day for after-hours access.'),
      ])
      ->required());
    $inputs->narrow('late_days')
      ->unless(Condition::equals('after_hours', TRUE))
      ->doesNotApply();

    $inputs->addSchema('keywords', Schema::list(Schema::string())
      ->title(t('Keywords'))
      ->default([]));

    $inputs->addSchema('holidays', Schema::options([
      'closed' => t('Closed'),
      'reduced' => t('Reduced hours'),
      'open' => t('Open as usual'),
    ])
      ->title(t('On public holidays'))
      ->default('closed'));
  }

}
