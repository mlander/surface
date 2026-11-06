<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Resource\Beacon;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\surface\Resource\Attribute\Operation;
use Drupal\surface\Resource\Attribute\Resource;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\OperationContext;
use Drupal\surface\Resource\ResourceInterface;
use Drupal\surface_resource_test\Storage\BeaconStorage;

/**
 * A trail beacon: the variant its mode chooses, mixed in flat.
 *
 * The flashing and the steady beacon declare the same three members
 * unlike: the code a constraint apart (at most five characters when
 * flashing), the color a default apart, and the pattern a kind apart (a
 * list of steps when flashing, a map of on and off seconds when steady).
 * Each is one key of the beacon's, declared as wide as both, and held
 * to the chosen variant's own definition.
 *
 * @see docs/resources.md#variants-mixed-in
 */
#[Resource('resource_test.beacon', storage: BeaconStorage::class, label: new TranslatableMarkup('Trail beacon'))]
final class BeaconResource implements ResourceInterface {

  /**
   * Configures the beacon, whose settings always exist.
   */
  #[Operation('configure', label: 'Configure the beacon', permission: 'administer site configuration')]
  public static function configure(): OperationContext {
    return new OperationContext('configure');
  }

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('title', 'string', t('Title'))
      ->setRequired(TRUE);
    $inputs->add('mode', 'string', t('Mode'))
      ->setRequired(TRUE);
    $inputs->mixInVariants('mode');
    $inputs->add('notes', 'string', t('Notes'));
  }

}
