<?php

declare(strict_types=1);

namespace Drupal\data_surface\Plugin\Validation\Constraint;

use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Attribute\HasNamedArguments;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Checks that a value names a language the site has.
 *
 * The existence constraint core is missing. Core validates that a plugin
 * exists, that an entity bundle exists and that an extension exists, but
 * nothing says "this is a language code", so every settings form that
 * wants one builds its own list of languages and calls that a
 * validation. Saying it as a constraint is what lets the list be derived
 * rather than declared, the same way PluginExists and
 * EntityBundleExists are read; see PLAN.md.
 *
 * Named the way core names its existence constraints: the option reads
 * as a permission (allowLocked, as PluginExists has allowFallback) and
 * the message names the offending value in a placeholder, as
 * PluginExists and EntityBundleExists do. Nothing here knows about
 * surfaces; the options resolver reads this constraint, not the reverse.
 *
 * Locked languages are out by default. "Not specified" and "not
 * applicable" are the absence of a language rather than a language, so a
 * setting naming one is almost always a mistake; a consumer that does
 * want them says so.
 *
 * @see \Drupal\data_surface\Plugin\DataSurfaceOptionsResolver\LanguageExistsOptions
 */
#[Constraint(
  id: 'LanguageExists',
  label: new TranslatableMarkup('Language exists', [], ['context' => 'Validation']),
  type: FALSE,
)]
final class LanguageExistsConstraint extends SymfonyConstraint {

  /**
   * Constructs a LanguageExistsConstraint.
   *
   * @param bool $allowLocked
   *   Whether to consider the locked languages, "not specified" and "not
   *   applicable", as languages that exist.
   * @param string $message
   *   The error message if the value names no language that exists.
   * @param array|null $groups
   *   The groups the constraint belongs to.
   * @param mixed $payload
   *   Domain-specific data attached to the constraint.
   */
  #[HasNamedArguments]
  public function __construct(
    public bool $allowLocked = FALSE,
    public string $message = "The '@langcode' language does not exist.",
    ?array $groups = NULL,
    mixed $payload = NULL,
  ) {
    parent::__construct(NULL, $groups, $payload);
  }

  /**
   * Names the language states this constraint accepts.
   *
   * The one place the option becomes the language manager's own
   * vocabulary, so the validator and the options resolver cannot read
   * the same constraint as two different sets of languages.
   *
   * @return int
   *   A bitmask of LanguageInterface state constants.
   */
  public function languageStates(): int {
    return $this->allowLocked
      ? LanguageInterface::STATE_ALL
      : LanguageInterface::STATE_CONFIGURABLE;
  }

}
