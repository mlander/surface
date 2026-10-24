<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\Core\Session\AccountInterface;
use Drupal\data_surface\Contract\SchemaResolver;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\Pipeline\PipelineGuard;
use Drupal\data_surface\Resource\AccessAnswer;
use Drupal\data_surface\Resource\OperationContext;
use Drupal\data_surface\ResourceBuild\ResourceRegistry;
use Drupal\data_surface_resource_test\Access\WallAccess;
use Drupal\data_surface_resource_test\Alter\GalleryAlter;
use Drupal\data_surface_resource_test\Alter\OilFinishAlter;
use Drupal\data_surface_resource_test\Alter\PlaqueAlter;
use Drupal\data_surface_resource_test\Alter\RoomAlter;
use Drupal\data_surface_resource_test\Alter\WallAlter;
use Drupal\data_surface_resource_test\Alter\WallOpeningAlter;
use Drupal\data_surface_resource_test\Resource\Gallery\GalleryResource;
use Drupal\data_surface_resource_test\Structure\Gallery\AnnexStructure;
use Drupal\data_surface_resource_test\Structure\Gallery\WallStructure;
use Drupal\data_surface_tool\OperationInputs;
use Drupal\Tests\data_surface\Traits\StaticAccessAgreementTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * An alter may answer access: after the owner's, the stricter standing.
 *
 * The gallery names no access class; its alters answer for it, for the
 * plaque it mixes in, for the wall it attaches twice (and the annex once),
 * for one of its variants and for the room at every entry of its
 * keys from the site. The wall has an access class of its own, asked
 * first, and an alter that tries to open it, asked last.
 *
 * @see docs/decisions.md#an-alter-may-answer-access-and-the-stricter-stands
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
#[IgnoreDeprecations]
class AlterAccessTest extends DataSurfaceKernelTestBase {

  use StaticAccessAgreementTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'serialization',
    'tool',
    'data_surface',
    'data_surface_tool',
    'data_surface_resource_test',
  ];

  /**
   * The gallery as installed.
   */
  protected const GALLERY = [
    'name' => 'The long gallery',
    'caption' => 'Seascapes',
    'credit' => 'The county museum',
    'north' => ['artwork' => 'Storm at sea', 'height' => 120],
    'south' => ['artwork' => 'Calm harbour', 'height' => 90],
    'medium' => 'oil',
    'finish' => ['varnish' => 'gloss'],
    'rooms' => ['east' => ['lights' => TRUE], 'west' => ['lights' => FALSE]],
  ];

  /**
   * Every key of the gallery, as installed.
   */
  protected const KEYS = ['name', 'caption', 'credit', 'north', 'south', 'medium', 'finish', 'rooms'];

  /**
   * The accounts, by what they are.
   *
   * @var array<string, \Drupal\Core\Session\AccountInterface>
   */
  protected array $accounts = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installConfig(['data_surface_resource_test']);
    // User 1 first, so none of the accounts below is the super user.
    $this->setUpCurrentUser();
    $this->accounts = [
      'keeper' => $this->createUser(['keep a gallery']),
      'wall inspector' => $this->createUser(['keep a gallery', 'inspect walls']),
      'freezer' => $this->createUser(['keep a gallery', 'freeze walls']),
      'plaque hider' => $this->createUser(['keep a gallery', 'hide plaques']),
      'gallery inspector' => $this->createUser(['keep a gallery', 'inspect gallery']),
    ];
    $this->actAs('keeper');
    GalleryAlter::$calls = 0;
    WallAccess::$asked = [];
  }

  /**
   * Makes one of the accounts the current user.
   *
   * @param string $who
   *   The account's label.
   *
   * @return \Drupal\Core\Session\AccountInterface
   *   The account.
   */
  protected function actAs(string $who): AccountInterface {
    $this->container->get('current_user')->setAccount($this->accounts[$who]);
    return $this->accounts[$who];
  }

  /**
   * Sets one of the State flags the alters' runtime answers read.
   *
   * @param string $flag
   *   The State key.
   * @param bool $on
   *   Whether it is set.
   */
  protected function flag(string $flag, bool $on = TRUE): void {
    $this->container->get('state')->set($flag, $on);
  }

  /**
   * Builds the gallery in its operation.
   *
   * @param bool $alone
   *   TRUE to build it for the account alone, as the full schema is.
   * @param string $operation
   *   The operation: configure, or curate, where the gallery's own alter
   *   answers too.
   *
   * @return \Drupal\data_surface\DataSurfaceInterface
   *   The built gallery.
   */
  protected function gallery(bool $alone = FALSE, string $operation = 'configure'): DataSurfaceInterface {
    $resources = $this->container->get('data_surface.resources');
    $context = $resources->operation(GalleryResource::class, $operation);
    return $alone ? $resources->buildStatic(GalleryResource::class, $context) : $resources->build(GalleryResource::class, $context);
  }

  /**
   * Emits the gallery's full schema, built for the account alone, inline.
   *
   * @param string $operation
   *   The operation.
   *
   * @return array
   *   The schema.
   */
  protected function full(string $operation = 'configure'): array {
    $served = $this->container->get('data_surface.contract_emitter')->emitFull($this->gallery(TRUE, $operation), 'resource_test.gallery', $operation);
    return SchemaResolver::inline(json_decode((string) json_encode($served->document['schema']), TRUE));
  }

  /**
   * Tests an alter answers through the access class's interface, as one.
   *
   * One interface for both roles: #[Alters] on the class says it is an
   * alter's answer, and an access class that carries #[Alters] is refused,
   * since it would answer twice, once in each role.
   */
  public function testAnAlterAnswersThroughTheOneInterface(): void {
    $gallery = $this->container->get('data_surface.resource_registry')->getDefinition(GalleryResource::class);
    $this->assertSame([GalleryAlter::class], array_values(array_map(static fn ($alter): string => $alter->class, array_filter($gallery->alters, static fn ($alter): bool => $alter->answersAccess))));
    $refuse = new \ReflectionMethod(ResourceRegistry::class, 'assertAccessClass');
    $refuse->invoke(NULL, GalleryResource::class, WallAccess::class);
    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage(GalleryResource::class . ' names ' . GalleryAlter::class . ' on access:, which carries #[Alters]. An alter that answers access is asked as an alter, after the owner\'s answer; an access class is the owner\'s own.');
    $refuse->invoke(NULL, GalleryResource::class, GalleryAlter::class);
  }

  /**
   * Tests the owner's answer is asked first, then each alter in order.
   */
  public function testTheOwnerFirstThenEachAlterInOrder(): void {
    $alters = array_map(static fn ($alter): string => $alter->class, $this->container->get('data_surface.resource_registry')->getAlters(WallStructure::class));
    $this->assertSame([WallAlter::class, WallOpeningAlter::class], $alters, 'Discovery order.');
    $this->assertTrue($this->container->get('data_surface.resource_registry')->getDefinition(GalleryResource::class)->answersAccess(), 'An alter answering is an answer, with no access class.');
    $this->gallery();
    $this->assertSame(['WallAccess', 'WallAlter', 'WallOpeningAlter'], array_values(array_unique(WallAccess::$asked)));

    // At the top, where reasons are said: the alter's lock locks the
    // gallery whole, with its reason.
    $this->actAs('gallery inspector');
    $this->assertSame('The gallery may be inspected, not changed.', (string) $this->gallery(FALSE, 'curate')->lockedWhole());
    $this->assertSame('The gallery may be inspected, not changed.', (string) $this->gallery(TRUE, 'curate')->lockedWhole(), 'Static: applied for the account alone.');
    $this->assertNull($this->gallery()->lockedWhole(), 'Only in the operation the alter names.');

    // Combined, the earlier of two answers as narrow keeps its reason.
    $owner = AccessAnswer::locked('The owner')->addCacheContexts(['user.permissions']);
    $alter = AccessAnswer::locked('The alter')->addCacheContexts(['user.permissions']);
    $this->assertSame('The owner', $owner->andIf($alter)->getReason());
  }

  /**
   * Tests an alter cannot open what the owner or an earlier alter closed.
   */
  public function testAnAlterCannotWiden(): void {
    // The owner's static lock, under an alter that answers open.
    $this->actAs('wall inspector');
    foreach ([FALSE, TRUE] as $alone) {
      $map = $this->gallery($alone)->getDefinitions();
      $this->assertTrue($map->isLocked('north') && $map->isLocked('south'), 'The wall\'s own lock stands.');
    }
    // An earlier alter's static lock, under the same.
    $this->actAs('freezer');
    foreach ([FALSE, TRUE] as $alone) {
      $map = $this->gallery($alone)->getDefinitions();
      $this->assertTrue($map->isLocked('north') && $map->isLocked('south'), 'The earlier alter\'s lock stands.');
    }
    // An earlier alter's runtime omission, under the same.
    $this->actAs('keeper');
    $this->flag(WallAlter::DOWN);
    $this->assertSame(['name', 'caption', 'credit', 'medium', 'finish', 'rooms'], $this->gallery()->getDefinitions()->names());
    // A locked wall keeps what is stored, whatever is posted.
    $this->flag(WallAlter::DOWN, FALSE);
    $this->actAs('freezer');
    $accepted = $this->pipeline()->accept($this->gallery(), ['north' => ['artwork' => 'Nothing', 'height' => 1]] + self::GALLERY, self::GALLERY);
    $this->assertSame(self::GALLERY['north'], $accepted['north']);
    // Nothing the opening alter answers makes the combination wider.
    $open = AccessAnswer::open()->addCacheContexts(['user.permissions']);
    foreach (['locked' => AccessAnswer::locked(), 'omitted' => AccessAnswer::omitted()] as $narrow => $answer) {
      $this->assertSame($narrow, $answer->addCacheContexts(['user.permissions'])->andIf($open)->getAnswer());
    }
  }

  /**
   * Tests each answer keeps its own kind, built for the account alone.
   */
  public function testEachAnswerKeepsItsKind(): void {
    // The wall's static lock, beside the alter's runtime answer: applied
    // for the account alone, and the key marked for the runtime one.
    $this->actAs('wall inspector');
    $alone = $this->gallery(TRUE)->getDefinitions();
    $this->assertTrue($alone->isLocked('north'));
    $this->assertTrue($alone->isRuntimeAccess('north'), 'Locked by the account alone, and marked for what the alter decides once the walls are known.');
    // The alter's static lock decides alone: nothing set aside after it is
    // asked, since the alter answers static for this account.
    $this->actAs('freezer');
    $alone = $this->gallery(TRUE)->getDefinitions();
    $this->assertTrue($alone->isLocked('north'));
    $this->assertFalse($alone->isRuntimeAccess('north'));
    // The alter's runtime omission: applied by build(), set aside for the
    // account alone, the key there and marked.
    $this->actAs('keeper');
    $this->flag(WallAlter::DOWN);
    $this->assertNull($this->gallery()->getDefinitions()->entry('north'));
    $alone = $this->gallery(TRUE)->getDefinitions();
    $this->assertFalse($alone->isLocked('north'));
    $this->assertTrue($alone->isRuntimeAccess('north') && $alone->isRuntimeAccess('south'));
    $this->assertFalse($alone->isRuntimeAccess('name'));
  }

  /**
   * Tests a static answer of an alter is asked once per account kind.
   */
  public function testStaticAlterAnswerIsKept(): void {
    $this->actAs('gallery inspector');
    $this->gallery(FALSE, 'curate');
    $first = GalleryAlter::$calls;
    $this->assertSame($this->assertionsOn() ? 2 : 1, $first, 'With assertions on, asked again, guarded.');
    $this->gallery(FALSE, 'curate');
    $this->gallery(TRUE, 'curate');
    $this->assertSame($first, GalleryAlter::$calls, 'Kept: nothing but the account decides it.');
    $this->actAs('keeper');
    $before = GalleryAlter::$calls;
    $this->gallery(FALSE, 'curate');
    $this->gallery(FALSE, 'curate');
    $this->assertSame($before + 2, GalleryAlter::$calls, 'A runtime answer is asked every time.');
  }

  /**
   * Answers whether assertions run here.
   *
   * @return bool
   *   TRUE when assert() is compiled and on.
   */
  protected function assertionsOn(): bool {
    $on = FALSE;
    assert(PipelineGuard::asserting($on));
    return $on;
  }

  /**
   * Tests the alters' answers in every frame the altered thing is built.
   */
  public function testEveryFrame(): void {
    $resources = $this->container->get('data_surface.resources');
    $annex = fn (bool $alone = FALSE): DataSurfaceInterface => $alone ? $resources->buildStatic(AnnexStructure::class, new OperationContext('configure')) : $resources->build(AnnexStructure::class, new OperationContext('configure'));

    // A structure attached twice, and by a second container: the alter
    // answers at every site.
    $this->actAs('freezer');
    $this->assertTrue($this->gallery()->getDefinitions()->isLocked('north'));
    $this->assertTrue($this->gallery()->getDefinitions()->isLocked('south'));
    $this->assertTrue($annex()->getDefinitions()->isLocked('wall'));
    $this->actAs('keeper');
    $this->flag(WallAlter::DOWN);
    $this->assertSame(['opening'], $annex()->getDefinitions()->names());
    $this->assertTrue($annex(TRUE)->getDefinitions()->isRuntimeAccess('wall'));
    $this->flag(WallAlter::DOWN, FALSE);

    // A mixin's members, held in the owner's frame.
    $this->actAs('plaque hider');
    foreach ([FALSE, TRUE] as $alone) {
      $this->assertSame(['name', 'north', 'south', 'medium', 'finish', 'rooms'], $this->gallery($alone)->getDefinitions()->names());
    }
    $this->actAs('keeper');
    $this->flag(PlaqueAlter::FIXED);
    $map = $this->gallery()->getDefinitions();
    $this->assertTrue($map->isHeld('caption') && $map->isHeld('credit'));
    $alone = $this->gallery(TRUE)->getDefinitions();
    $this->assertFalse($alone->isHeld('caption'));
    $this->assertTrue($alone->isRuntimeAccess('caption') && $alone->isRuntimeAccess('credit'));
    $this->flag(PlaqueAlter::FIXED, FALSE);

    // One variant, and not the other.
    $this->actAs('freezer');
    $variants = $this->gallery()->getDefinitions()->entry('finish')->variants->variants;
    $this->assertNotNull($variants['oil']->child->lockedWhole(), 'Offered read only.');
    $this->assertNull($variants['print']->child->lockedWhole());
    $this->actAs('keeper');
    $this->flag(OilFinishAlter::BANNED);
    $this->assertSame(['print'], array_keys($this->gallery()->getDefinitions()->entry('finish')->variants->variants));
    $alone = $this->gallery(TRUE)->getDefinitions();
    $this->assertSame(['oil', 'print'], array_keys($alone->entry('finish')->variants->variants));
    $this->assertTrue($alone->isRuntimeAccess('finish'));
    $this->flag(OilFinishAlter::BANNED, FALSE);

    // The structure at every entry of keys from the site.
    $this->flag(RoomAlter::DARK);
    $this->assertTrue($this->gallery()->getDefinitions()->isLocked('rooms'));
    $alone = $this->gallery(TRUE)->getDefinitions();
    $this->assertFalse($alone->isLocked('rooms'));
    $this->assertTrue($alone->isRuntimeAccess('rooms'));
    $this->flag(RoomAlter::DARK, FALSE);

    // At the top: locked whole, or refused.
    $resources = $this->container->get('data_surface.resources');
    $context = $resources->operation(GalleryResource::class, 'curate');
    $this->assertTrue($resources->access(GalleryResource::class, $context)->isAllowed());
    $this->flag(GalleryAlter::CLOSED);
    $refused = $resources->access(GalleryResource::class, $context);
    $this->assertTrue($refused->isForbidden());
    $this->assertTrue($resources->access(GalleryResource::class, $resources->operation(GalleryResource::class, 'configure'))->isAllowed(), 'Only in the operation the alter names.');
    $alone = $this->gallery(TRUE, 'curate');
    $this->assertNull($alone->lockedWhole(), 'Built for the account alone, a runtime answer at the top is set aside.');
    $marked = array_filter($alone->getDefinitions()->names(), [$alone->getDefinitions(), 'isRuntimeAccess']);
    $this->assertSame(self::KEYS, array_values($marked), 'And every key is marked.');
    $this->flag(GalleryAlter::CLOSED, FALSE);
    $this->actAs('gallery inspector');
    $this->assertTrue($resources->access(GalleryResource::class, $context)->isAllowed(), 'Locked at the top lets the caller in.');
    $this->assertSame('The gallery may be inspected, not changed.', (string) $this->gallery(FALSE, 'curate')->lockedWhole());
  }

  /**
   * Tests an alter's static lock on the parent composes with a runtime child.
   */
  public function testStaticLockOverRuntimeChild(): void {
    // The gallery locked whole by the alter, for the account alone; the
    // walls inside it waiting on the wall's alter, which is runtime for
    // this account: locked with the parent, and marked.
    $this->actAs('gallery inspector');
    $alone = $this->gallery(TRUE, 'curate');
    $this->assertNotNull($alone->lockedWhole());
    $this->assertTrue($alone->getDefinitions()->isRuntimeAccess('north'));
    $this->assertFalse($alone->getDefinitions()->isRuntimeAccess('name'), 'Static at the top: nothing set aside there.');
    $full = $this->full('curate');
    $this->assertTrue($full['readOnly']);
    $this->assertTrue($full['properties']['north']['readOnly']);
    $this->assertSame('live', $full['properties']['north']['x-surface']['access']);
  }

  /**
   * Tests the full schema states each account's static answers.
   */
  public function testTheFullSchemaIsTheAccounts(): void {
    $this->actAs('freezer');
    $full = $this->full();
    foreach (['north', 'south'] as $wall) {
      $this->assertTrue($full['properties'][$wall]['readOnly'], $wall);
      $this->assertTrue($full['properties'][$wall]['x-surface']['locked'], $wall);
      $this->assertArrayNotHasKey('access', $full['properties'][$wall]['x-surface'], 'Static: stated, not marked.');
    }

    $this->actAs('plaque hider');
    $full = $this->full();
    $this->assertSame(['name', 'north', 'south', 'medium', 'finish', 'rooms'], array_keys($full['properties']));

    $this->actAs('keeper');
    $full = $this->full();
    foreach (['caption', 'credit', 'north', 'south', 'finish', 'rooms'] as $key) {
      $this->assertArrayNotHasKey('readOnly', $full['properties'][$key], $key);
      $this->assertSame('live', $full['properties'][$key]['x-surface']['access'], $key);
    }
    $this->assertArrayNotHasKey('access', $full['properties']['name']['x-surface'] ?? []);

    // What the full schema depends on: the account's permissions, and
    // nothing a runtime answer read.
    $served = $this->container->get('data_surface.contract_emitter')->emitFull($this->gallery(TRUE), 'resource_test.gallery', 'configure');
    $this->assertContains('user.permissions', $served->getCacheContexts());
    $this->assertSame(-1, $served->getCacheMaxAge());
    $these = $this->container->get('data_surface.contract_emitter')->emit($this->gallery(), self::GALLERY, 'resource_test.gallery', 'configure');
    $this->assertSame(0, $these->getCacheMaxAge(), 'The schema for these answers depends on what the runtime answers read.');
  }

  /**
   * Tests the tool listing and the read tool, for the account that asks.
   */
  public function testTheToolsAreTheAccounts(): void {
    $tools = $this->container->get('plugin.manager.tool');
    $id = 'data_surface:resource_test.gallery:configure';
    $this->assertSame('keep a gallery', $tools->getDefinition($id)->getPermission(), 'The operation\'s permission stays the owner\'s.');

    $this->actAs('freezer');
    $values = $tools->createInstance($id)->getInputDefinition(OperationInputs::VALUES);
    $this->assertTrue($values->getPropertyDefinition('north')->isLocked());
    $this->assertStringNotContainsString('decided once', (string) $values->getPropertyDefinition('north')->getDescription());

    $this->actAs('plaque hider');
    $values = $tools->createInstance($id)->getInputDefinition(OperationInputs::VALUES);
    $this->assertSame(['name', 'north', 'south', 'medium', 'finish', 'rooms'], array_keys($values->getPropertyDefinitions()));

    $this->actAs('keeper');
    $values = $tools->createInstance($id)->getInputDefinition(OperationInputs::VALUES);
    $this->assertFalse($values->getPropertyDefinition('north')->isLocked());
    $this->assertStringContainsString('Whether this may be changed is decided once the thing it belongs to is known', (string) $values->getPropertyDefinition('north')->getDescription());

    $read = function (): array {
      $tool = $this->container->get('plugin.manager.tool')->createInstance('data_surface:resource_test.gallery:configure:read');
      $this->assertTrue($tool->access());
      $tool->execute();
      return $tool->getOutputValue(OperationInputs::VALUES);
    };
    $this->actAs('plaque hider');
    $this->assertSame(['name', 'north', 'south', 'medium', 'finish', 'rooms'], array_keys($read()), 'Left out by the account alone.');
    $this->actAs('keeper');
    $this->flag(WallAlter::DOWN);
    $this->assertSame(['name', 'caption', 'credit', 'medium', 'finish', 'rooms'], array_keys($read()), 'Left out once the walls are known.');
    $this->flag(WallAlter::DOWN, FALSE);
    $this->flag(GalleryAlter::CLOSED);
    $this->assertFalse($this->container->get('plugin.manager.tool')->createInstance('data_surface:resource_test.gallery:curate:read')->access(), 'Left out at the top: refused.');
    $this->assertTrue($this->container->get('plugin.manager.tool')->createInstance('data_surface:resource_test.gallery:configure:read')->access());
  }

  /**
   * Tests the full schema's static marks hold in every situation.
   */
  public function testTheStaticMarksAgreeWithTheBuild(): void {
    $state = $this->container->get('state');
    $flags = [WallAlter::DOWN, PlaqueAlter::FIXED, OilFinishAlter::BANNED, RoomAlter::DARK, GalleryAlter::CLOSED];
    $situations = [
      'as installed' => static function () use ($state, $flags): void {
        foreach ($flags as $flag) {
          $state->set($flag, FALSE);
        }
      },
    ];
    foreach ($flags as $flag) {
      $situations[$flag] = static function () use ($state, $flags, $flag): void {
        foreach ($flags as $other) {
          $state->set($other, $other === $flag);
        }
      };
    }
    $this->assertGreaterThan(80, $this->assertStaticAccessAgrees(GalleryResource::class, 'configure', $this->accounts, $situations));
    $this->assertGreaterThan(80, $this->assertStaticAccessAgrees(GalleryResource::class, 'curate', $this->accounts, $situations));
  }

}
