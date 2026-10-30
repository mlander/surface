<?php

declare(strict_types=1);

namespace Drupal\surface_schema_test\Resource;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\surface\Resource\ArrangementGroup;
use Drupal\surface\Resource\Attribute\Operation;
use Drupal\surface\Resource\Attribute\Resource;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\ResourceInterface;
use Drupal\surface_schema_test\Keys\AliasMarks;
use Drupal\surface_schema_test\Keys\AliasTypes;
use Drupal\surface_schema_test\Shape\MarkAction;
use Drupal\surface_schema_test\Storage\AliasSettingsStorage;

/**
 * Alias settings, as pathauto's settings form asks for two of them.
 *
 * A checkbox per type, stored as the list of the types checked, and a
 * select per punctuation mark, stored as a map, each in a config object
 * whose schema is written by hand. What is installed leaves an entry out
 * of each: Content, which is locked, and the hyphen, as a mark the site
 * added after the settings were saved.
 */
#[Resource('schema_test.aliases', storage: AliasSettingsStorage::class)]
#[Operation('configure', label: new TranslatableMarkup('Configure the aliases'), permission: 'administer site configuration')]
final class AliasSettingsResource implements ResourceInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->addEach('types', 'boolean', from: AliasTypes::class, default: FALSE)
      ->setLabel(t('Types with aliases'));
    $inputs->addEach('marks', MarkAction::class, from: AliasMarks::class)
      ->setLabel(t('Punctuation'));
    $inputs->describe('marks', group: ArrangementGroup::details('punctuation', t('Punctuation'), collapsed: TRUE));
  }

}
