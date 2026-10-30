<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Core\Cache\Cache;
use Drupal\surface\Contract\SchemaReader;
use Drupal\surface\SurfaceInterface;
use Drupal\surface\Pipeline\Outbound;
use Drupal\surface\ResourceBuild\Resources;
use Drupal\surface_resource_test\Resource\Poster\PosterResource;
use Drupal\surface_resource_test\Variants\PosterLayouts;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests a request pays for the listed variants it reads, not for the list.
 *
 * The poster's provider lists five hundred layouts more, each a reference
 * into defs the list holds once. The build holds every one unsettled; a
 * door that walks every variant for its secrets (a read, a submission)
 * builds none whose document states no secret; and the shared document is
 * prepared once, sharing its arrays with what the provider listed.
 *
 * Decision: see
 * docs/decisions.md#the-static-build-is-cached-the-rest-is-per-request.
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class ManyListedVariantsTest extends SurfaceKernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'serialization',
    'tool',
    'surface',
    'surface_tool',
    'surface_resource_test',
  ];

  /**
   * How many layouts the provider lists beside the three it always does.
   */
  protected const MANY = 500;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installConfig(['surface_resource_test']);
    $this->setUpCurrentUser([], ['administer site configuration']);
  }

  /**
   * Lists the layouts, one of them with a secret if asked.
   *
   * @param bool $secret
   *   Whether one more layout, `vault`, declares a secret of its own.
   * @param bool $lazy
   *   Whether the provider lists them by value and label alone, each
   *   schema answered when asked.
   */
  protected function listMany(bool $secret = FALSE, bool $lazy = FALSE): void {
    $layouts = [];
    for ($i = 0; $i < self::MANY; $i++) {
      $layouts['shelf_' . $i] = ['label' => 'Shelf ' . $i, 'schema' => ['$ref' => '#/$defs/poster.shelf.' . $i]];
    }
    if ($secret) {
      $layouts['vault'] = [
        'label' => 'Vault',
        'schema' => [
          'type' => 'object',
          'properties' => ['pin' => ['type' => 'string', 'title' => 'PIN', 'writeOnly' => TRUE]],
          'additionalProperties' => FALSE,
        ],
      ];
    }
    $this->container->get('state')->set(PosterLayouts::STATE, $layouts);
    $this->container->get('state')->set(PosterLayouts::DEFS, static::shelfDefs());
    $this->container->get('state')->set(PosterLayouts::LAZY, $lazy);
    Cache::invalidateTags([PosterLayouts::TAG]);
    $this->resources()->forgetBuilds();
    PosterLayouts::$read = [];
  }

  /**
   * Writes the defs the shelves share: one per shelf, a few keys each.
   *
   * @return array<string, array>
   *   The defs, by name.
   */
  protected static function shelfDefs(): array {
    $defs = [];
    for ($i = 0; $i < self::MANY; $i++) {
      $defs['poster.shelf.' . $i] = [
        'type' => 'object',
        'properties' => [
          'heading' => ['type' => 'string', 'title' => 'Heading of shelf ' . $i, 'maxLength' => 80],
          'rows' => ['type' => 'integer', 'title' => 'Rows', 'minimum' => 1, 'maximum' => 12, 'default' => 2],
          'finish' => ['type' => 'string', 'title' => 'Finish', 'enum' => ['oak', 'ash', 'steel']],
        ],
        'additionalProperties' => FALSE,
      ];
    }
    return $defs;
  }

  /**
   * Gets the build step.
   *
   * @return \Drupal\surface\ResourceBuild\Resources
   *   The service.
   */
  protected function resources(): Resources {
    $resources = $this->container->get('surface.resources');
    assert($resources instanceof Resources);
    return $resources;
  }

  /**
   * Builds the poster for the current account.
   *
   * @return \Drupal\surface\SurfaceInterface
   *   The build.
   */
  protected function poster(): SurfaceInterface {
    return $this->resources()->buildOperation(PosterResource::class, 'configure');
  }

  /**
   * Lists the layouts the site lists whose variant is built, by value.
   *
   * @param \Drupal\surface\SurfaceInterface $built
   *   The build.
   *
   * @return string[]
   *   The values.
   */
  protected function settled(SurfaceInterface $built): array {
    $settled = [];
    foreach ($built->getDefinitions()->entry('panels')->variants->variants as $value => $variant) {
      if ($variant->isDeferred() && $variant->isSettled()) {
        $settled[] = (string) $value;
      }
    }
    return $settled;
  }

  /**
   * Lists the shelves whose variant is built.
   *
   * @param \Drupal\surface\SurfaceInterface $built
   *   The build.
   *
   * @return string[]
   *   The values.
   */
  protected function shelves(SurfaceInterface $built): array {
    return array_values(array_filter($this->settled($built), static fn (string $value): bool => str_starts_with($value, 'shelf_')));
  }

  /**
   * Tests a read and a submission build only the layout they choose.
   */
  public function testOnlyTheChosenLayoutIsBuilt(): void {
    $this->listMany();
    $built = $this->poster();
    $variants = $built->getDefinitions()->entry('panels')->variants;
    $this->assertCount(self::MANY + 3, $variants->variants);
    $this->assertTrue($variants->variant('shelf_7')->isDeferred());
    $this->assertSame([], $this->settled($built), 'The build builds no layout the site lists.');

    // Every door projects what it answers: no layout is built to find
    // secrets, since no layout's document states one.
    $this->assertSame([], Outbound::secrets($built));
    $this->assertSame([], $this->settled($built));
    $stored = ['layout' => 'shelf_7', 'panels' => ['heading' => 'New in', 'rows' => 3, 'finish' => 'oak']];
    $this->assertSame($stored, Outbound::project($built, $stored));
    $this->assertSame(['shelf_7'], $this->shelves($built), 'The chosen shelf alone is built, beside the default layout at most.');
    $this->assertLessThanOrEqual(2, count($this->settled($built)));

    // A submission choosing one layout builds that one.
    $sent = ['layout' => 'shelf_9', 'panels' => ['heading' => 'Sale', 'rows' => 1]];
    $accepted = $this->container->get('surface.pipeline')->accept($built, $sent, $stored);
    $this->assertSame('Sale', $accepted['panels']['heading']);
    $this->assertSame(['shelf_7', 'shelf_9'], $this->shelves($built));
  }

  /**
   * Tests a list given by value and label is read an entry at a time.
   */
  public function testLazyListReadsAnEntryWhenBuilt(): void {
    $this->listMany(lazy: TRUE);
    $built = $this->poster();
    $this->assertCount(self::MANY + 3, $built->getDefinitions()->entry('panels')->variants->variants);
    $this->assertSame([], PosterLayouts::$read, 'Building reads no entry.');

    // Past the site's list limit, the full schema names the values alone.
    $this->container->get('surface.contract_emitter')->emitFull($built, 'resource_test.poster', 'configure', 'Configure the poster');
    $this->assertSame([], PosterLayouts::$read, 'The full schema reads no entry past the limit.');

    // The pipeline asks every variant for its secrets: it reads each
    // entry's schema, and the defs once, and builds none.
    $this->assertSame([], Outbound::secrets($built));
    $this->assertSame([], $this->shelves($built));
    $this->assertCount(1, array_keys(PosterLayouts::$read, '$defs', TRUE));

    // The chosen one is built.
    $stored = ['layout' => 'shelf_7', 'panels' => ['heading' => 'New in', 'rows' => 3, 'finish' => 'oak']];
    $this->assertSame($stored, Outbound::project($built, $stored));
    $this->assertSame(['shelf_7'], $this->shelves($built));
  }

  /**
   * Tests a list read an entry at a time states what the list given whole does.
   */
  public function testBothSpellingsStateTheSame(): void {
    $emitter = $this->container->get('surface.contract_emitter');
    $values = ['layout' => 'shelf_7', 'panels' => ['heading' => 'New in', 'rows' => 3]];
    $documents = [];
    foreach ([FALSE, TRUE] as $lazy) {
      $this->listMany(lazy: $lazy);
      $built = $this->poster();
      $documents[$lazy ? 'lazy' : 'whole'] = [
        'emit' => $emitter->emit($built, $values, 'resource_test.poster', 'configure', 'Configure the poster')->document,
        'full' => $emitter->emitFull($built, 'resource_test.poster', 'configure', 'Configure the poster')->document,
        'within' => $this->container->get('surface.site_list_budget')->withLimit(1000, static fn () => $emitter->emitFull($built, 'resource_test.poster', 'configure', 'Configure the poster')->document),
      ];
    }
    $this->assertSame(json_encode($documents['whole']), json_encode($documents['lazy']));
  }

  /**
   * Tests a layout whose document states a secret is still asked, and alone.
   */
  public function testLayoutWithSecretIsStillAsked(): void {
    $this->listMany(secret: TRUE);
    $built = $this->poster();
    $this->assertSame(['panels' => ['pin' => TRUE]], Outbound::secrets($built));
    $this->assertSame(['vault'], $this->settled($built), 'The vault is built to be asked; no shelf is.');
    $stored = ['layout' => 'shelf_3', 'panels' => ['heading' => 'Tools', 'pin' => '1234']];
    $this->assertArrayNotHasKey('pin', Outbound::project($built, $stored)['panels'], 'A secret another layout declares is never returned under the key.');
  }

  /**
   * Tests what a document can say that keeps its structures from being skipped.
   */
  public function testWhatDocumentSaysOfSecrets(): void {
    $none = static fn (string $id): bool => FALSE;
    $this->assertTrue(SchemaReader::holdsNoSecret(SchemaReader::prepared(['$defs' => static::shelfDefs()]), $none));
    $secret = ['type' => 'object', 'properties' => ['pin' => ['type' => 'string', 'writeOnly' => TRUE]]];
    $this->assertFalse(SchemaReader::holdsNoSecret(SchemaReader::prepared(['$defs' => ['deep' => ['properties' => ['inner' => $secret]]]]), $none));
    // A def a shape is known by may be secret in the shape's own words.
    $shaped = ['$ref' => '#/$defs/event.license', '$defs' => ['event.license' => ['type' => 'string']]];
    $this->assertTrue(SchemaReader::holdsNoSecret(SchemaReader::prepared($shaped), $none));
    $this->assertFalse(SchemaReader::holdsNoSecret(SchemaReader::prepared($shaped), static fn (string $id): bool => $id === 'event.license'));
  }

  /**
   * Tests a large document is prepared once, sharing what it was given.
   */
  public function testLargeDocumentIsPreparedOnce(): void {
    // The reader loaded before anything is measured.
    SchemaReader::prepared(['type' => 'object']);
    gc_collect_cycles();
    $start = memory_get_usage();
    $document = ['$defs' => static::shelfDefs()];
    $listed = memory_get_usage() - $start;
    $first = SchemaReader::prepared($document);
    $once = memory_get_usage() - $start - $listed;
    $second = SchemaReader::prepared($document);
    $twice = memory_get_usage() - $start - $listed - $once;
    $this->assertSame($first, $second);
    $this->assertSame(['$schema' => 'https://json-schema.org/draft/2020-12/schema'] + $document, $first, 'Nothing to convert, nothing converted.');
    // Read as it is, it shares its arrays and strings with the document:
    // a fraction of what the document holds, and nothing more the second
    // time.
    $this->assertLessThan($listed / 4, $once, sprintf('Preparing a document of %d bytes held %d more.', $listed, $once));
    $this->assertLessThan($listed / 20, $twice, sprintf('Preparing it again held %d more.', $twice));
  }

}
