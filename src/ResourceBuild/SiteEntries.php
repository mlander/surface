<?php

declare(strict_types=1);

namespace Drupal\data_surface\ResourceBuild;

use Drupal\Core\DependencyInjection\ClassResolverInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\data_surface\Contract\SchemaReader;
use Drupal\data_surface\Contract\SchemaResolver;
use Drupal\data_surface\Pipeline\PipelineGuard;
use Drupal\data_surface\Resource\BlueprintInterface;

/**
 * Reads one entry of a provider's variants when a door reads it.
 *
 * The inputs of a variant a provider lists without its schema
 * (EntrySchemasInterface): fill() asks the provider for that entry's
 * schema, and for the defs the entries share only when it refers to one,
 * and reads it as the build would have read it from the list. The defs
 * are prepared once per provider and language for the rest of the
 * request, so every entry that refers to them is read from one document
 * and each def is one structure, wherever it is attached.
 *
 * Decision: see
 * docs/decisions.md#the-static-build-is-cached-the-rest-is-per-request.
 *
 * @internal
 *   Named by the read definitions the build step registers.
 */
final class SiteEntries {

  /**
   * The defs each provider's entries share, prepared, with their hash.
   *
   * @var array<string, array{0: array, 1: string}|null>
   */
  private static array $shared = [];

  /**
   * Constructs a SiteEntries.
   *
   * @param \Drupal\Core\DependencyInjection\ClassResolverInterface $classResolver
   *   The class resolver, which builds the provider.
   * @param \Drupal\Core\Language\LanguageManagerInterface $languageManager
   *   The language manager: a label in the defs is in the language read.
   */
  public function __construct(
    protected readonly ClassResolverInterface $classResolver,
    protected readonly LanguageManagerInterface $languageManager,
  ) {}

  /**
   * Reads one entry's variant into a blueprint.
   *
   * @param \Drupal\data_surface\Resource\BlueprintInterface $inputs
   *   The variant's blueprint.
   * @param class-string<\Drupal\data_surface\Resource\EntrySchemasInterface> $from
   *   The provider.
   * @param string $value
   *   The entry's id.
   */
  public function fill(BlueprintInterface $inputs, string $from, string $value): void {
    [$root, $pointer, $hash] = $this->document($from, $value);
    SchemaReader::fill($inputs, $root, $pointer, $hash);
  }

  /**
   * Gets the document one entry's variant is read from.
   *
   * What the build reads for an entry a list gives a schema: the def
   * itself when the schema is a reference alone to one of the defs, else
   * the schema with the defs beside it.
   *
   * @param class-string<\Drupal\data_surface\Resource\EntrySchemasInterface> $from
   *   The provider.
   * @param string $value
   *   The entry's id.
   *
   * @return array{0: array, 1: string, 2: string}
   *   The document, prepared; the pointer of the variant in it; and the
   *   document's hash.
   */
  public function document(string $from, string $value): array {
    // The build names only a provider implementing EntrySchemasInterface.
    $provider = $this->classResolver->getInstanceFromDefinition($from);
    $schema = PipelineGuard::onBehalf(PipelineGuard::PROVIDER, $from, 'entrySchema', static fn (): array => $provider->entrySchema($value));
    $key = $from . ':' . $this->languageManager->getCurrentLanguage()->getId() . ':' . $this->languageManager->getConfigOverrideLanguage()->getId();
    if (!array_key_exists($key, self::$shared)) {
      $defs = PipelineGuard::onBehalf(PipelineGuard::PROVIDER, $from, 'entryDefs', static fn (): array => $provider->entryDefs());
      $root = $defs === [] ? NULL : SchemaReader::prepared(['$defs' => $defs]);
      self::$shared[$key] = $root === NULL ? NULL : [$root, ResourceBlueprint::documentHash($root), $defs];
    }
    $shared = self::$shared[$key];
    $reference = is_string($schema['$ref'] ?? NULL) && count($schema) === 1 ? SchemaResolver::defName($schema['$ref']) : NULL;
    if ($shared !== NULL && $reference !== NULL && isset($shared[0]['$defs'][$reference])) {
      return [$shared[0], '/$defs/' . str_replace(['~', '/'], ['~0', '~1'], $reference), $shared[1]];
    }
    $root = SchemaReader::prepared($schema + ($shared === NULL ? [] : ['$defs' => $shared[2]]));
    return [$root, '', ResourceBlueprint::documentHash($root)];
  }

  /**
   * Forgets the defs read, when something they were read from may change.
   *
   * Resources::invalidateTags() and Resources::forgetBuilds() call this.
   */
  public static function forget(): void {
    self::$shared = [];
  }

}
