<?php

declare(strict_types=1);

namespace Drupal\surface_reader_test\Resource;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\surface\Reader\ConfigSchemaReader;
use Drupal\surface\Resource\Attribute\Operation;
use Drupal\surface\Resource\Attribute\Resource;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\OperationContext;
use Drupal\surface\Resource\ResourceInterface;
use Drupal\surface_reader_test\Storage\ConditionHostStorage;

/**
 * One condition, read straight from config schema and stored as core does.
 *
 * Its condition is the structure ConfigSchemaReader reads for
 * `condition.plugin.[id]`: the keys every condition holds, and the chosen
 * condition's own at `configuration`, which the config object keeps flat
 * beside the id, as a block's visibility keeps each condition.
 *
 * @see \Drupal\surface\Storage\FlatVariantsLayout
 */
#[Resource('reader_test.condition_host', storage: ConditionHostStorage::class, label: new TranslatableMarkup('Condition host'))]
final class ConditionHostResource implements ResourceInterface {

  /**
   * Configures the condition, whose settings always exist.
   */
  #[Operation('configure', label: 'Configure the condition', permission: 'administer site configuration')]
  public static function configure(): OperationContext {
    return new OperationContext('configure');
  }

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->attach('condition', \Drupal::service(ConfigSchemaReader::class)->structure('condition.plugin.[id]'), t('Condition'));
  }

}
