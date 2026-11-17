<?php

declare(strict_types=1);

namespace Drupal\surface_examples\Resource;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\surface\Resource\Attribute\Operation;
use Drupal\surface\Resource\Attribute\Resource;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\ResourceInterface;
use Drupal\surface_examples\Storage\ProgramStorage;
use Drupal\surface_examples\Structure\FeesStructure;
use PortableSchema\Builder\Schema;

/**
 * Example 10: access, static and runtime, and how each operation behaves.
 *
 * The fees are a structure whose access class locks them for an account
 * without the permission, which the account alone decides, and once the
 * program is published, which only what is stored decides. Checking a
 * program changes nothing, and says so: readOnly.
 */
#[Resource('event.program', storage: ProgramStorage::class)]
#[Operation('configure',
  label: new TranslatableMarkup('Configure the program'),
  description: new TranslatableMarkup('Sets the program: its headline, how many sessions it has, whether it is published, and its fees.'),
  permission: 'administer site configuration',
)]
#[Operation('check',
  label: new TranslatableMarkup('Check a program'),
  description: new TranslatableMarkup('Checks a program against every rule the program configuration applies, and saves nothing.'),
  permission: 'administer site configuration',
  readOnly: TRUE,
)]
final class ProgramResource implements ResourceInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->addSchema('headline', Schema::string()
      ->title(t('Headline'))
      ->required());

    $inputs->addSchema('sessions', Schema::integer()
      ->title(t('Sessions'))
      ->default(8)
      ->minimum(1)
      ->maximum(30));

    $inputs->addSchema('published', Schema::bool()
      ->title(t('Published'))
      ->default(FALSE));

    $inputs->attach('fees', FeesStructure::class, t('Fees'));
  }

}
