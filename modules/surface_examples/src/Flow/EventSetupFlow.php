<?php

declare(strict_types=1);

namespace Drupal\surface_examples\Flow;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\surface\Flow\ChecksBeforeApplyInterface;
use Drupal\surface\Flow\FlowInterface;
use Drupal\surface\Flow\StagedInterface;
use Drupal\surface\Flow\StepsInterface;
use Drupal\surface\Pipeline\Violation;
use Drupal\surface\Resource\Attribute\Flow;

/**
 * Example 19: the event set up in three steps, written as a class.
 *
 * The first two steps split the setup's keys between them, so the
 * resource declares no groups for them. The third, the notices, is
 * another resource, drawn while ticket sales are open or a banner is
 * shown, so a banner can always be hidden. Before Save applies the stage,
 * the flow refuses a banner shown while sales are closed. The route at
 * /surface-examples/19 names the flow by its id.
 */
#[Flow('event.setup_flow', label: new TranslatableMarkup('Set up the event'), keep: 'short')]
final class EventSetupFlow implements FlowInterface, ChecksBeforeApplyInterface {

  use StringTranslationTrait;

  /**
   * Constructs an EventSetupFlow.
   *
   * @param \Drupal\Core\StringTranslation\TranslationInterface $string_translation
   *   The string translation service.
   */
  public function __construct(TranslationInterface $string_translation) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * {@inheritdoc}
   */
  public function defineSteps(StepsInterface $steps): void {
    $steps->step('basics', 'event.setup', 'configure')
      ->keys('name', 'date', 'capacity')
      ->label($this->t('The event'))
      ->description($this->t('What the event is called, when it is, and how many people it holds.'));

    $steps->step('tickets', 'event.setup', 'configure')
      ->keys('price', 'sales_open', 'note')
      ->label($this->t('Tickets'));

    $steps->step('notices', 'event.notices', 'configure')
      ->label($this->t('Notices'))
      ->description($this->t('Drawn while ticket sales are open or a banner is shown.'))
      ->when(static fn (StagedInterface $staged): bool => $staged->value('event.setup', 'sales_open') === TRUE || $staged->value('event.notices', 'show_banner') === TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function beforeApply(StagedInterface $staged): array {
    if ($staged->value('event.notices', 'show_banner') === TRUE && $staged->value('event.setup', 'sales_open') !== TRUE) {
      return [new Violation('', '', $this->t('The notices show a banner while ticket sales are closed; open sales or hide the banner.'), 'banner_while_sales_closed')];
    }
    return [];
  }

}
