<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel\Fixture;

use Drupal\Core\StringTranslation\Translator\TranslatorInterface;

/**
 * A translator for one made-up language, held in memory.
 *
 * Added to a kernel test's string_translation service, it answers the
 * sources it was handed for its one language and nothing else, so a test
 * can render the same object in the default language and in this one by
 * setting the default language code, as the language negotiation does
 * for a request. It touches no configuration and no locale storage.
 */
final class FakeLanguageTranslator implements TranslatorInterface {

  /**
   * Constructs a FakeLanguageTranslator.
   *
   * @param string $langcode
   *   The language it translates into.
   * @param array<string, string> $translations
   *   Each translation, keyed by its source string. A plural's source and
   *   translation hold their forms joined by PoItem::DELIMITER.
   */
  public function __construct(
    protected readonly string $langcode,
    protected readonly array $translations,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getStringTranslation($langcode, $string, $context) {
    if ($langcode !== $this->langcode) {
      return FALSE;
    }
    return $this->translations[$string] ?? FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function reset(): void {}

}
