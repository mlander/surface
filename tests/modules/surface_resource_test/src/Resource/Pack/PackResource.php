<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Resource\Pack;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\surface\Resource\Attribute\Operation;
use Drupal\surface\Resource\Attribute\Resource;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\OperationContext;
use Drupal\surface\Resource\ResourceInterface;
use Drupal\surface_resource_test\Storage\PackStorage;

/**
 * A backpack: the variant its carry chooses at its load, children inside.
 *
 * The frame pack and the daypack are variants at "load", each a
 * #[Variant] of this class there. The frame pack holds a hip belt, a
 * child at a fixed key, and its pockets, a collection; the daypack holds
 * a rain cover, a map holding a pouch, which makes it a structure with
 * no id. Each child is the variant's own, in the variant's frame under
 * the load, and both declare a capacity, each its own.
 *
 * @see docs/resources.md#variants
 */
#[Resource('resource_test.pack', storage: PackStorage::class, label: new TranslatableMarkup('Backpack'))]
final class PackResource implements ResourceInterface {

  /**
   * Configures the pack, whose settings always exist.
   */
  #[Operation('configure', label: 'Configure the pack', permission: 'administer site configuration')]
  public static function configure(): OperationContext {
    return new OperationContext('configure');
  }

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('title', 'string', t('Title'))
      ->setRequired(TRUE);
    $inputs->add('carry', 'string', t('Carry'))
      ->setRequired(TRUE);
    $inputs->attachVariants('load', by: 'carry', label: t('Load'));
    $inputs->add('notes', 'string', t('Notes'));
  }

}
