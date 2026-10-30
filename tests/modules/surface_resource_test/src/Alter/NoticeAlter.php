<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Alter;

use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\surface\Refinement\DoesNotApply;
use Drupal\surface\Resource\AltersInputsInterface;
use Drupal\surface\Resource\Attribute\Alters;
use Drupal\surface\Resource\Attribute\RefinesInput;
use Drupal\surface\Resource\BlueprintAdditionsInterface;
use Drupal\surface_resource_test\Structure\Notice\NoticeStructure;

/**
 * Mounts a signature on the notice, and a footer.
 *
 * The signature applies only to a notice sent by email; the footer always
 * applies.
 */
#[Alters(NoticeStructure::class)]
final class NoticeAlter implements AltersInputsInterface {

  /**
   * {@inheritdoc}
   */
  public function alterInputs(BlueprintAdditionsInterface $inputs): void {
    $inputs->add('signature', 'string', 'Signature', default: 'The team')
      ->setRequired(TRUE);
    $inputs->add('footer', 'string', 'Footer', default: 'Sent by the notice board');
  }

  /**
   * A signature applies only to a notice sent by email.
   */
  #[RefinesInput('signature')]
  public static function signatureOnlyByEmail(DataDefinitionInterface $signature, string $channel): DataDefinitionInterface|DoesNotApply {
    return $channel === 'email' ? $signature : DoesNotApply::here();
  }

}
