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
 * A backpack: the variant its carry chooses mixed in flat, children too.
 *
 * The frame pack and the daypack are variants of the carry, each a
 * #[Variant] of this class at "carry". The frame pack brings a hip belt,
 * a child at a fixed key, and its pockets, a collection; the daypack
 * brings a rain cover, a map holding a pouch, which makes it a structure
 * with no id. Each child is the pack's own child at its key,
 * applying only where its variant is chosen. Both declare the capacity
 * alike but for the label and the description, so it is one key, which
 * wears the daypack's, the variant discovery found first.
 *
 * @see docs/resources.md#variants-mixed-in
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
    $inputs->mixInVariants('carry');
    $inputs->add('notes', 'string', t('Notes'));
  }

}
