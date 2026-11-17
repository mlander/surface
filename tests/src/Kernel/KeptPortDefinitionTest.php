<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Tests\surface\HandCorpus\KeptPortCases;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests a port wired to a key of a class outside the three keeps it.
 *
 * A port's key is held as its schema; one whose definition is of a class
 * no schema derives a definition of (NodeDefinition::DERIVED) keeps that
 * definition beside it (Attachment::$wiredDefinitions,
 * Surface::$portDefinitions), and the watched-value check is handed it.
 * The cases (KeptPortCases): a NotAMapDefinition port, a
 * BaseFieldDefinition port and an integer port, each with the check
 * sealed in, a value of the key's type and one of another, through the
 * container and bound directly, fresh and woken (the NotAMapDefinition
 * case fresh alone). A value the check
 * refuses is withheld from the refiner that watches the port. Every
 * answer is the one 38648793 gave, where the definition was held whole.
 *
 * Decision: see docs/decisions.md#the-document-is-written-from-schemas.
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class KeptPortDefinitionTest extends SurfaceKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'serialization',
    'tool',
    'surface',
  ];

  /**
   * What each case answers, taken at 38648793.
   */
  protected const ANSWERS = [
    'notAMap right' => [
      'accepted' => [
        'k' => [
          'z' => 'a',
        ],
        'att' => [
          'v' => 'abcdef',
        ],
      ],
      'refusals' => [],
      'withheld' => [],
      'port' => ['Drupal\\Tests\\surface\\HandCorpus\\NotAMapDefinition', 'Pcx'],
    ],
    'notAMap right bound' => [
      'withheld' => [],
      'port' => 'Drupal\\Tests\\surface\\HandCorpus\\NotAMapDefinition',
    ],
    'notAMap wrong' => 'Drupal\\surface\\Pipeline\\TypeMismatchException: The value at "k" must be of type map, string given.',
    'notAMap wrong bound' => [
      'withheld' => ['p'],
      'port' => 'Drupal\\Tests\\surface\\HandCorpus\\NotAMapDefinition',
    ],
    'baseField right' => [
      'accepted' => [
        'k' => [[
          'value' => 'a',
        ],
        ],
        'att' => [
          'v' => 'abcdef',
        ],
      ],
      'refusals' => [],
      'withheld' => [],
      'port' => ['Drupal\\Core\\Field\\BaseFieldDefinition', 'Bf'],
    ],
    'baseField right bound' => [
      'withheld' => [],
      'port' => 'Drupal\\Core\\Field\\BaseFieldDefinition',
    ],
    'baseField wrong' => 'Drupal\\surface\\Pipeline\\TypeMismatchException: The value at "k.0" must be of type map, string given.',
    'baseField wrong bound' => [
      'withheld' => [],
      'port' => 'Drupal\\Core\\Field\\BaseFieldDefinition',
    ],
    'baseField woken right' => [
      'accepted' => [
        'k' => [[
          'value' => 'a',
        ],
        ],
        'att' => [
          'v' => 'abcdef',
        ],
      ],
      'refusals' => [],
      'withheld' => [],
      'port' => ['Drupal\\Core\\Field\\BaseFieldDefinition', 'Bf'],
    ],
    'baseField woken right bound' => [
      'withheld' => [],
      'port' => 'Drupal\\Core\\Field\\BaseFieldDefinition',
    ],
    'baseField woken wrong' => 'Drupal\\surface\\Pipeline\\TypeMismatchException: The value at "k.0" must be of type map, string given.',
    'baseField woken wrong bound' => [
      'withheld' => [],
      'port' => 'Drupal\\Core\\Field\\BaseFieldDefinition',
    ],
    'integer right' => [
      'accepted' => [
        'k' => 5,
        'att' => [
          'v' => 'abcdef',
        ],
      ],
      'refusals' => ['att:v'],
      'withheld' => [],
      'port' => ['Drupal\\Core\\TypedData\\DataDefinition', 'Pint'],
    ],
    'integer right bound' => [
      'withheld' => [],
      'port' => 'Drupal\\Core\\TypedData\\DataDefinition',
    ],
    'integer wrong' => [
      'accepted' => [
        'k' => 'word',
        'att' => [
          'v' => 'abcdef',
        ],
      ],
      'refusals' => ['k:'],
      'withheld' => ['p'],
      'port' => ['Drupal\\Core\\TypedData\\DataDefinition', 'Pint'],
    ],
    'integer wrong bound' => [
      'withheld' => ['p'],
      'port' => 'Drupal\\Core\\TypedData\\DataDefinition',
    ],
    'integer woken right' => [
      'accepted' => [
        'k' => 5,
        'att' => [
          'v' => 'abcdef',
        ],
      ],
      'refusals' => ['att:v'],
      'withheld' => [],
      'port' => ['Drupal\\Core\\TypedData\\DataDefinition', 'Pint'],
    ],
    'integer woken right bound' => [
      'withheld' => [],
      'port' => 'Drupal\\Core\\TypedData\\DataDefinition',
    ],
    'integer woken wrong' => [
      'accepted' => [
        'k' => 'word',
        'att' => [
          'v' => 'abcdef',
        ],
      ],
      'refusals' => ['k:'],
      'withheld' => ['p'],
      'port' => ['Drupal\\Core\\TypedData\\DataDefinition', 'Pint'],
    ],
    'integer woken wrong bound' => [
      'withheld' => ['p'],
      'port' => 'Drupal\\Core\\TypedData\\DataDefinition',
    ],
  ];

  /**
   * Tests each case answers as before.
   */
  public function testEachCaseAnswersAsBefore(): void {
    $answers = KeptPortCases::answers($this->container->get('surface.watched_value_check'), $this->container->get('surface.pipeline'));
    $this->assertSame(self::ANSWERS, $answers);
  }

}
