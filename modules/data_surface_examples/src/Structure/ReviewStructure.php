<?php

declare(strict_types=1);

namespace Drupal\data_surface_examples\Structure;

use Drupal\data_surface\Refinement\Condition;
use Drupal\data_surface\Resource\Attribute\Structure;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\StructureInterface;

/**
 * Example 8: who reviewed a page and when, members a resource mixes in.
 *
 * Mixed in with mixIn(), its two keys are the resource's own: stored flat
 * beside the others, drawn among them, and named in a refusal by their
 * own names. Its rule is bound in that resource's frame.
 */
#[Structure('event.review')]
final class ReviewStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('reviewed_on', 'string', t('Reviewed on'))
      ->setDescription(t('YYYY-MM-DD.'))
      ->setSetting('widget', 'date')
      ->addConstraint('Regex', [
        'pattern' => '/^\d{4}-(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])$/D',
        'message' => t('Write the date as YYYY-MM-DD, such as 2026-10-19.'),
      ]);

    $inputs->add('reviewed_by', 'string', t('Reviewed by'));

    $inputs->narrow('reviewed_by')
      ->when(Condition::present('reviewed_on'))
      ->require()
      ->because(t('Required once a review date is set.'));
  }

}
