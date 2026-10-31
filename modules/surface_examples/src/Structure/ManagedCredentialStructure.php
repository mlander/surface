<?php

declare(strict_types=1);

namespace Drupal\surface_examples\Structure;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;

/**
 * Example 14: the credential as a key the site manages, named.
 *
 * Offered only while the module that manages keys is on, whose constraint
 * names the keys it lists: what is stored is the key's name, never its
 * value.
 */
#[Structure('event.mailer.credential.managed',
  label: new TranslatableMarkup('Managed credential'),
  description: new TranslatableMarkup('The mailer credential as the name of a key the site manages, whose value is read where it is used.'),
)]
final class ManagedCredentialStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('key_id', 'string', t('Key'))
      ->setDescription(t('A key the site manages; its value is read where it is used, never stored here.'))
      ->setRequired(TRUE)
      ->addConstraint('ManagedKey');
  }

}
