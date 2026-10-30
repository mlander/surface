<?php

declare(strict_types=1);

namespace Drupal\surface_examples_keys\Alter;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\surface\Refinement\Condition;
use Drupal\surface\Resource\AltersInputsInterface;
use Drupal\surface\Resource\Attribute\Alters;
use Drupal\surface\Resource\BlueprintAdditionsInterface;
use Drupal\surface_examples\Resource\WebhookResource;
use Drupal\surface_examples_keys\Compute\ManagedKeyValue;

/**
 * Example 14, the alter's way: a managed key, on a resource that has none.
 *
 * The webhook declares a pasted key and knows nothing of this module. The
 * alter says both halves of putting a managed key in its place. What is
 * asked: it mounts the name of a managed key under this module's name,
 * draws it right after the pasted key, and, once a key is chosen, rules
 * the pasted one out: no box on the form, and a pasted key sent is
 * refused, naming the managed key. What is answered: once a key is
 * chosen, the code that uses the webhook reads the managed key's value at
 * `api_key`, through Resources::resolved(). What is stored for the pasted
 * key is kept, for the day no managed key is chosen, and the managed
 * key's value is never stored on the webhook.
 */
#[Alters(WebhookResource::class)]
final class WebhookKeyAlter implements AltersInputsInterface {

  use StringTranslationTrait;

  /**
   * Constructs the alter, which the container builds as a service.
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
  public function alterInputs(BlueprintAdditionsInterface $inputs): void {
    $inputs->add('key_id', 'string', $this->t('Key'))
      ->setDescription($this->t('A key the site manages, used in place of a pasted one.'))
      ->addConstraint('ManagedKey');
    $inputs->describe('third_party_settings.surface_examples_keys', label: $this->t('Managed key'), after: 'api_key');
    // A managed key chosen: a pasted one means nothing beside it.
    $inputs->narrow('api_key')
      ->when(Condition::present('key_id'))
      ->doesNotApply();
    // And where the webhook is used, the managed key's value in its place.
    $inputs->resolve('api_key', from: ['key_id'], using: ManagedKeyValue::class);
  }

}
