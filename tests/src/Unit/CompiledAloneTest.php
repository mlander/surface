<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Unit;

use Drupal\Core\DependencyInjection\ClassResolverInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\Core\TypedData\TypedDataManagerInterface;
use Drupal\Core\Validation\ConstraintManager;
use Drupal\surface\Contract\ContractEmitter;
use Drupal\surface\Options\SurfaceOptions;
use Drupal\surface\Validation\ConstraintRunner;
use Drupal\surface\Validation\PortableValidator;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PortableSchema\Builder\Schema;
use PortableSchema\Registry;

/**
 * Tests which documents are compiled over a registry of themselves alone.
 *
 * A document that reaches no schema outside itself is compiled alone;
 * one that may reach another schema, by any keyword the validator
 * resolves through the registry, is compiled over the site's registry.
 *
 * @see docs/decisions.md#a-document-that-reaches-nothing-else-is-compiled-alone
 */
#[Group('surface')]
class CompiledAloneTest extends UnitTestCase {

  /**
   * The id the documents are held under.
   */
  protected const ID = 'urn:surface:validate:abc';

  /**
   * Tests the documents that are compiled alone.
   *
   * @param array|bool $document
   *   The document, decoded.
   */
  #[DataProvider('providerAlone')]
  public function testAlone(array|bool $document): void {
    $this->assertFalse(PortableValidator::reachesOutside($document, self::ID));
  }

  /**
   * Provides documents that reach nothing outside themselves.
   *
   * @return array<string, array{0: array|bool}>
   *   Each document.
   */
  public static function providerAlone(): array {
    $property = ['type' => 'string', 'maxLength' => 4];
    return [
      'a boolean document' => [TRUE],
      'an empty document' => [[]],
      'no reference' => [['type' => 'object', 'properties' => ['a' => $property]]],
      'its own $id at the root' => [['$id' => self::ID, 'type' => 'string']],
      'a fragment to its defs' => [['$defs' => ['a' => $property], 'properties' => ['a' => ['$ref' => '#/$defs/a']]]],
      'a fragment to an anchor' => [['$defs' => ['a' => ['$anchor' => 'a'] + $property], 'items' => ['$ref' => '#a']]],
      'the root by fragment' => [['properties' => ['child' => ['$ref' => '#']]]],
      'its own id' => [['properties' => ['child' => ['$ref' => self::ID]]]],
      'its own id and a fragment' => [['$defs' => ['a' => $property], 'properties' => ['a' => ['$ref' => self::ID . '#/$defs/a']]]],
      'a fragment deep in branches' => [['$defs' => ['a' => $property], 'anyOf' => [['allOf' => [['items' => ['$ref' => '#/$defs/a']]]], ['type' => 'null']]]],
      'checks and the extension' => [['portable-check' => ['drupal:Length' => ['max' => 4]], 'x-surface' => ['by' => 'a', 'variants' => ['b' => []]]]],
    ];
  }

  /**
   * Tests the documents that are compiled over the site's registry.
   *
   * @param array $document
   *   The document, decoded.
   */
  #[DataProvider('providerOverSite')]
  public function testOverSite(array $document): void {
    $this->assertTrue(PortableValidator::reachesOutside($document, self::ID));
  }

  /**
   * Provides documents that may reach a schema outside themselves.
   *
   * @return array<string, array{0: array}>
   *   Each document.
   */
  public static function providerOverSite(): array {
    $at = static fn (array $schema): array => ['type' => 'object', 'properties' => ['a' => ['anyOf' => [['items' => $schema]]]]];
    return [
      'a $ref to a library type' => [$at(['$ref' => 'portable:string'])],
      'an absolute $ref' => [$at(['$ref' => 'https://example.com/schemas/a'])],
      'an absolute $ref with a fragment' => [$at(['$ref' => 'https://example.com/schemas/a#/$defs/b'])],
      'a relative $ref' => [$at(['$ref' => 'other'])],
      'a relative $ref with a fragment' => [$at(['$ref' => 'other#/$defs/b'])],
      'a $ref to an id that starts as its own' => [$at(['$ref' => self::ID . 'd'])],
      'an empty $ref' => [$at(['$ref' => ''])],
      'a $ref that is no string' => [['properties' => ['$ref' => ['type' => 'string']]]],
      'a $dynamicRef' => [$at(['$dynamicRef' => '#meta'])],
      'a $recursiveRef' => [$at(['$recursiveRef' => '#'])],
      'a portable-typeBy' => [$at(['portable-typeBy' => ['href' => '#/$defs/{kind}', 'source' => ['kind' => '1/kind']]])],
      'a $id below the root' => [$at(['$id' => 'urn:other', 'type' => 'string'])],
      'a $id at the root that is no string' => [['$id' => ['urn:other'], 'type' => 'string']],
      'a $ref inside a const' => [$at(['const' => ['$ref' => 'portable:string']])],
      'a $ref inside the extension' => [['x-surface' => ['of' => ['$ref' => 'portable:string']]]],
    ];
  }

  /**
   * Tests the registry each document is compiled over, and both validate.
   */
  public function testCompiledOver(): void {
    $translation = $this->getStringTranslationStub();
    $options = (new \ReflectionClass(SurfaceOptions::class))->newInstanceWithoutConstructor();
    $site = Registry::withTypes();
    $validator = new PortableValidator($site, new ContractEmitter($options, $translation), new ConstraintRunner($this->createMock(TypedDataManagerInterface::class), $this->createMock(ClassResolverInterface::class)), $this->createMock(ConstraintManager::class), $translation, $options, new AnonymousUserSession());
    $compiled = new \ReflectionProperty(PortableValidator::class, 'compiled');
    $documents = [
      'alone' => [['$defs' => ['short' => ['type' => 'string', 'maxLength' => 2]], 'properties' => ['a' => ['$ref' => '#/$defs/short']]], 1],
      'over the site' => [['properties' => ['a' => ['$ref' => 'portable:string', 'maxLength' => 2]]], count($site->ids()) + 1],
    ];
    foreach ($documents as $case => [$document, $schemas]) {
      $compiled->setValue($validator, []);
      $found = $validator->held(Schema::from(['$schema' => 'https://portable-schema.dev/v1/schema', 'type' => 'object'] + $document), (object) ['a' => 'abc']);
      $this->assertSame([['a', 'maxLength']], array_map(static fn ($one): array => [$one->path, $one->code], $found), $case);
      $kept = $compiled->getValue($validator);
      $this->assertCount(1, $kept, $case);
      $this->assertCount($schemas, $kept[array_key_first($kept)][3]->ids(), $case);
    }
  }

}
