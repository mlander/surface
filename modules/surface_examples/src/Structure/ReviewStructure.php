<?php

declare(strict_types=1);

namespace Drupal\surface_examples\Structure;

use Drupal\surface\Refinement\Condition;
use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;
use PortableSchema\Builder\Schema;

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
    $inputs->add('reviewed_on', Schema::string()
      ->title(t('Reviewed on'))
      ->description(t('YYYY-MM-DD.'))
      ->check('drupal:Regex', [
        'pattern' => '/^\d{4}-(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])$/D',
        'message' => t('Write the date as YYYY-MM-DD, such as 2026-10-19.'),
      ])
      ->ui(widget: 'date'));

    $inputs->add('reviewed_by', Schema::string()
      ->title(t('Reviewed by')));

    $inputs->narrow('reviewed_by')
      ->when(Condition::present('reviewed_on'))
      ->require()
      ->because(t('Required once a review date is set.'));
  }

}
