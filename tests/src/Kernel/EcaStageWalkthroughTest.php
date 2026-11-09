<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Core\Config\Schema\SchemaCheckTrait;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\eca\Entity\Eca;
use Drupal\node\Entity\Node;
use Drupal\surface\Storage\StageStorage;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The ECA model the examples ship as optional config, run end to end.
 *
 * `eca.eca.surface_stage_walkthrough`, installed disabled once ECA,
 * eca_base, eca_user, eca_tool, modeler_api and the entity example are
 * and the site has a page type. Enabled here, and triggered by its custom
 * event as drush triggers it, as the anonymous user, it switches to the
 * account its token names (uid 1), opens a stage, stages a title on two
 * pages, lists the stage and applies it, each step through a surface
 * tool. When a step fails it says why and discards the stage. Skipped
 * where the code base has no eca, modeler_api or eca_tool, which are no
 * Composer packages of this module.
 *
 * @see modules/surface_examples/config/optional/eca.eca.surface_stage_walkthrough.yml
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class EcaStageWalkthroughTest extends SurfaceKernelTestBase {

  use ContentEntityFixtureTrait;
  use UserCreationTrait;

  /**
   * The model's id.
   */
  protected const MODEL = 'surface_stage_walkthrough';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    ...self::ENTITY_MODULES,
    'modeler_api',
    'eca',
    'eca_base',
    'eca_user',
    'eca_tool',
    'surface_examples',
  ];

  /**
   * {@inheritdoc}
   *
   * Off for the install alone: the model's tool actions have no schema.
   * eca_tool declares `action.configuration.eca_tool:*`, and core's
   * fallback for a schema name (TypedConfigManager::getFallbackName())
   * replaces the last segment split by `.` or `:` with `*`, so only a tool
   * id of one segment, with no dot and no colon, reaches it; a surface
   * tool id (`surface:surface.stage:create`) never does. In its place,
   * assertModelSchema() checks the installed model against its schema and
   * sets aside only what is under the configuration of an eca_tool action.
   */
  // phpcs:ignore DrupalPractice.Objects.StrictSchemaDisabled.StrictConfigSchema
  protected $strictConfigSchema = FALSE;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    // Where Composer and a hand install put a module.
    $root = dirname(__DIR__, 6);
    foreach (['eca', 'modeler_api', 'eca_tool'] as $module) {
      if (glob($root . '/modules/{,*/}' . $module . '/' . $module . '.info.yml', GLOB_BRACE) === []) {
        $this->markTestSkipped(sprintf('The code base has no %s.', $module));
      }
    }
    Eca::setTesting();
    parent::setUp();
    $this->installEntityFixture();
    // The page bundle's tools, and the actions eca_tool derives from them,
    // exist once the bundle does.
    $this->container->get('plugin.manager.tool')->clearCachedDefinitions();
    $this->container->get('plugin.manager.action')->clearCachedDefinitions();
    $this->installConfig(['surface', 'surface_examples']);
    // The account the model switches to: uid 1.
    $this->setUpCurrentUser(admin: TRUE);
    foreach (['First', 'Second'] as $title) {
      Node::create(['type' => 'page', 'title' => $title, 'field_description' => 'In the hall.'])->save();
    }
    $this->container->get('config.installer')->installOptionalConfig();
  }

  /**
   * Checks the installed model against its config schema.
   *
   * Every error but those under the configuration of an action whose
   * plugin is an eca_tool one, which no schema reaches, fails the test.
   */
  protected function assertModelSchema(): void {
    $name = 'eca.eca.' . self::MODEL;
    $data = $this->container->get('config.storage')->read($name);
    $this->assertIsArray($data);
    $checker = new class() {
      use SchemaCheckTrait;
    };
    $errors = $checker->checkConfigSchema($this->container->get('config.typed'), $name, $data);
    $this->assertNotFalse($errors, 'The model has a schema.');
    $tools = array_keys(array_filter($data['actions'], static fn (array $action): bool => str_starts_with((string) $action['plugin'], 'eca_tool:')));
    $this->assertNotEmpty($tools);
    $left = array_filter(is_array($errors) ? $errors : [], static function (string $key) use ($name, $tools): bool {
      foreach ($tools as $action) {
        if (str_starts_with($key, $name . ':actions.' . $action . '.configuration')) {
          return FALSE;
        }
      }
      return TRUE;
    }, ARRAY_FILTER_USE_KEY);
    $this->assertSame([], $left, 'The model validates against its schema, but the eca_tool actions\' configuration.');
  }

  /**
   * Enables the model, which ships disabled, and triggers its event.
   *
   * As `drush eca:trigger:custom_event` dispatches it: as the anonymous
   * user.
   */
  protected function trigger(): void {
    $model = Eca::load(self::MODEL);
    $this->assertNotNull($model, 'The optional config is installed.');
    $this->assertFalse($model->status(), 'It ships disabled.');
    $model->setStatus(TRUE)->save();
    $this->container->get('current_user')->setAccount(new AnonymousUserSession());
    // Named by text: eca_base is no package of this module, so static
    // analysis has none.
    $event = 'Drupal\\eca_base\\Event\\CustomEvent';
    // @phpstan-ignore class.notFound
    $this->container->get('event_dispatcher')->dispatch(new $event('surface_stage_walkthrough', []), constant('Drupal\\eca_base\\BaseEvents::CUSTOM'));
    $this->assertTrue($this->container->get('current_user')->isAnonymous(), 'The account is switched back.');
  }

  /**
   * Tests the model stages two titles, lists them and applies them.
   */
  public function testTheModelStagesListsAndApplies(): void {
    $this->assertModelSchema();
    $this->trigger();

    $nodes = $this->container->get('entity_type.manager')->getStorage('node');
    $nodes->resetCache();
    $this->assertSame('Staged by ECA (page 1)', $nodes->load(1)?->label());
    $this->assertSame('Staged by ECA (page 2)', $nodes->load(2)?->label());

    $records = $this->container->get('keyvalue.expirable')->get('surface.stage')->getAll();
    $this->assertCount(1, $records);
    $record = reset($records);
    $this->assertSame(StageStorage::APPLIED, $record['status']);
    $this->assertSame([1, 'ECA walk-through'], [$record['owner'], $record['label']]);
    $this->assertCount(2, $record['applied']);

    // The tokens the model set: the stage's id, the count of the writes
    // and the tools' messages.
    $said = array_map('strval', $this->container->get('messenger')->messagesByType('status'));
    $this->assertContains(sprintf('The stage %s holds 2 writes. List the staged writes changes nothing: the values were accepted and prepared, and nothing was written.', $record['id']), $said);
    $this->assertCount(2, $said, implode("\n", $said));
  }

  /**
   * Tests a write that fails discards the stage and says why.
   *
   * The second page is gone, so its staged write is refused: the model
   * says so with the tool's message, discards the stage and stops, and
   * the first page keeps its stored title.
   */
  public function testFailedWriteDiscardsTheStage(): void {
    $this->container->get('entity_type.manager')->getStorage('node')->load(2)?->delete();
    $this->trigger();

    $this->assertSame([], $this->container->get('keyvalue.expirable')->get('surface.stage')->getAll(), 'The stage is discarded.');
    $nodes = $this->container->get('entity_type.manager')->getStorage('node');
    $nodes->resetCache();
    $this->assertSame('First', $nodes->load(1)?->label());
    $said = array_map('strval', $this->container->get('messenger')->messagesByType('status'));
    $this->assertCount(1, $said, implode("\n", $said));
    $this->assertMatchesRegularExpression('/^The second page was not staged, so the stage \S+ is discarded: \S/', $said[0]);
  }

}
