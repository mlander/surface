<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Unit;

use Drupal\Core\Session\AnonymousUserSession;
use Drupal\Core\TypedData\TypedDataManagerInterface;
use Drupal\surface\Contract\ContractEmitter;
use Drupal\surface\Options\SurfaceOptions;
use Drupal\surface\Validation\PortableValidator;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;
use PortableSchema\Builder\Schema;
use PortableSchema\Registry;

/**
 * Tests a document with no data surface behind it is said in its own words.
 *
 * A document another host wrote carries its messages in
 * `x-portable-messages`; with no constraint of a place to say a refusal,
 * the document's message for the code is the refusal's, over the code's
 * sentence.
 *
 * @see docs/decisions.md#validation-runs-through-the-dialects-validator
 */
#[Group('surface')]
class PortableValidatorTest extends UnitTestCase {

  /**
   * Tests the document's message for a code is said where nothing else is.
   */
  public function testDocumentMessageWins(): void {
    $translation = $this->getStringTranslationStub();
    $options = (new \ReflectionClass(SurfaceOptions::class))->newInstanceWithoutConstructor();
    $validator = new PortableValidator(Registry::withTypes(), new ContractEmitter($options, $translation), $this->createMock(TypedDataManagerInterface::class), $translation, $options, new AnonymousUserSession());
    $schema = Schema::from([
      '$schema' => 'https://portable-schema.dev/v1/schema',
      'type' => 'object',
      'properties' => [
        'slug' => [
          'type' => 'string',
          'pattern' => '^[a-z]+$',
          'x-portable-messages' => ['pattern' => 'Doc says so.'],
        ],
        'code' => ['type' => 'string', 'pattern' => '^[a-z]+$'],
      ],
    ]);
    $found = $validator->held($schema, (object) ['slug' => 'Not', 'code' => 'Not']);
    $said = [];
    foreach ($found as $one) {
      $said[$one->path . ' ' . $one->code] = (string) $one->message;
    }
    ksort($said);
    $this->assertSame(['code pattern' => 'This value is not valid.', 'slug pattern' => 'Doc says so.'], $said);
  }

}
