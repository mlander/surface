<?php

declare(strict_types=1);

namespace Drupal\data_surface_examples\Resource;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\data_surface\Resource\Attribute\Operation;
use Drupal\data_surface\Resource\Attribute\Resource;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\ResourceInterface;
use Drupal\data_surface_examples\Storage\ProgramStorage;
use Drupal\data_surface_examples\Structure\FeesStructure;

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
    $inputs->add('headline', 'string', t('Headline'))
      ->setRequired(TRUE);
    $inputs->add('sessions', 'integer', t('Sessions'), default: 8)
      ->addConstraint('Range', ['min' => 1, 'max' => 30]);
    $inputs->add('published', 'boolean', t('Published'), default: FALSE);
    $inputs->attach('fees', FeesStructure::class)
      ->setLabel(t('Fees'));
  }

}
