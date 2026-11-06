<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Resource\Kit;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\surface\Resource\Attribute\Operation;
use Drupal\surface\Resource\Attribute\Resource;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\OperationContext;
use Drupal\surface\Resource\ResourceInterface;
use Drupal\surface_resource_test\Storage\KitStorage;

/**
 * A camping kit: the variant its kind chooses, mixed in flat.
 *
 * The tent and the stove are variants of the kind, each a #[Variant] of
 * this class at "kind", and their members are the kit's own keys, stored
 * flat beside the kind, where mixInVariants() stands: before the notes,
 * in the order discovery found the variants, the stove's then the tent's.
 * Both declare the weight alike, so it is one key for either; the fuel
 * is the stove's, the poles and the fly the tent's.
 *
 * @see docs/resources.md#variants-mixed-in
 */
#[Resource('resource_test.kit', storage: KitStorage::class, label: new TranslatableMarkup('Camping kit'))]
final class KitResource implements ResourceInterface {

  /**
   * Configures the kit, whose settings always exist.
   */
  #[Operation('configure', label: 'Configure the kit', permission: 'administer site configuration')]
  public static function configure(): OperationContext {
    return new OperationContext('configure');
  }

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('title', 'string', t('Title'))
      ->setRequired(TRUE);
    $inputs->add('kind', 'string', t('Kind'))
      ->setRequired(TRUE);
    $inputs->mixInVariants('kind');
    $inputs->add('notes', 'string', t('Notes'));
  }

}
