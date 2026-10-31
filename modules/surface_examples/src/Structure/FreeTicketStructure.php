<?php

declare(strict_types=1);

namespace Drupal\surface_examples\Structure;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\Attribute\Variant;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;
use Drupal\surface_examples\Resource\RegistrationStep3Resource;

/**
 * The ticket, when registration is free: a note, and nothing to pay.
 *
 * Example 3's ticket variant for `free`. No storage: example 3 stores it
 * under its ticket key.
 */
#[Structure('registration.step3.ticket.free',
  label: new TranslatableMarkup('Free ticket'),
  description: new TranslatableMarkup('The ticket when registration costs nothing: a note shown beside the register button.'),
)]
#[Variant(of: RegistrationStep3Resource::class, at: 'ticket', for: 'free', label: new TranslatableMarkup('Free'))]
final class FreeTicketStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('note', 'string', t('Note'))
      ->setDescription(t('Shown beside the register button, for example "Donations welcome".'));
  }

}
