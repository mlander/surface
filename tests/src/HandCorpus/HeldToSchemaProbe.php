<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\HandCorpus;

use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\surface\Entry;
use Drupal\surface\Storage\HeldToConfigSchemaTrait;

/**
 * Holds one key of a hand-built surface to a config schema written by hand.
 *
 * For the door corpus: what a config storage's widerThanSchema() says of
 * each key, asked of a key and a schema directly, so a surface no storage
 * holds can be held to schemas no module declares.
 */
final class HeldToSchemaProbe {

  use HeldToConfigSchemaTrait;

  /**
   * Constructs a probe.
   *
   * @param \Drupal\Core\Config\TypedConfigManagerInterface $typedConfig
   *   The typed config manager, which resolves a schema's named types.
   */
  public function __construct(protected readonly TypedConfigManagerInterface $typedConfig) {
  }

  /**
   * Gives the config schema type the probe stands for, which none declares.
   *
   * @return string
   *   The type.
   */
  public function configSchemaType(): string {
    return 'surface_hand_corpus.none';
  }

  /**
   * Says where a key is wider than a schema.
   *
   * @param \Drupal\surface\Entry $entry
   *   The key.
   * @param array|null $schema
   *   The schema of its property, or NULL for none.
   * @param string $path
   *   The key's path.
   * @param bool $strict
   *   Whether the schema's root is fully validatable.
   *
   * @return array<string, string>
   *   Why, by dotted path.
   */
  public function wider(Entry $entry, ?array $schema, string $path, bool $strict): array {
    $wider = [];
    $this->entryWider($entry, $schema, $path, $strict, $wider);
    return $wider;
  }

}
