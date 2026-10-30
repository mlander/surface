<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\Core\Routing\RouteObjectInterface;
use Drupal\surface\SurfaceInterface;
use Drupal\surface\Form\SurfaceFormBuilderInterface;
use Drupal\surface\Form\SurfaceOperationForm;
use Drupal\surface\ResourceBuild\OperationRoute;
use Drupal\surface_schema_test\Shape\MarkAction;
use Drupal\surface\Tool\OperationInputs;
use Drupal\Tests\surface\Traits\ServedControllerTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Route;

/**
 * Tests an entry left out of a stored map reads as its default at every door.
 *
 * The schema test module's alias settings store a checkbox per type as a
 * list of ids, and a select per punctuation mark as a map, each installed
 * with an entry left out: Content, which is locked and starts checked, and
 * the hyphen, which starts as "Replace by separator", as a mark the site
 * added after the settings were saved. The operation form merged the
 * declared defaults under what is stored one level deep, so it drew both
 * empty and saved the hyphen as null; every door now lays the defaults
 * under a stored map entry by entry.
 *
 * @see docs/decisions.md#defaults-follow-the-definitions-under-a-stored-value
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class DefaultsUnderStoredMapTest extends SurfaceKernelTestBase {

  use ServedControllerTrait;
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
    'surface_schema_test',
  ];

  /**
   * The resource.
   */
  protected const RESOURCE = 'schema_test.aliases';

  /**
   * What storage holds for the maps, as loaded: two entries left out.
   */
  protected const HELD = [
    'types' => ['user' => TRUE],
    'marks' => ['period' => MarkAction::REMOVE, 'underscore' => MarkAction::KEEP],
  ];

  /**
   * What each door that draws reads for the maps, those two at defaults.
   */
  protected const READ = [
    'types' => ['node' => TRUE, 'term' => FALSE, 'user' => TRUE],
    'marks' => ['hyphen' => MarkAction::REPLACE, 'period' => MarkAction::REMOVE, 'underscore' => MarkAction::KEEP],
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installConfig(['surface_schema_test']);
    $this->setUpCurrentUser(admin: TRUE);
  }

  /**
   * Builds the resource in its operation.
   *
   * @return \Drupal\surface\SurfaceInterface
   *   The built resource.
   */
  protected function aliases(): SurfaceInterface {
    $resources = $this->container->get('surface.resources');
    return $resources->build(self::RESOURCE, $resources->operation(self::RESOURCE, 'configure'));
  }

  /**
   * Pushes the request of a route serving the operation form.
   */
  protected function route(): void {
    $route = new Route('/schema-test/aliases', [
      '_form' => SurfaceOperationForm::class,
      OperationRoute::RESOURCE => self::RESOURCE,
      OperationRoute::OPERATION => 'configure',
    ]);
    $stack = $this->container->get('request_stack');
    $request = Request::create('/schema-test/aliases', 'POST');
    $request->setSession($stack->getSession());
    $request->attributes->set(RouteObjectInterface::ROUTE_NAME, 'surface_schema_test.aliases');
    $request->attributes->set(RouteObjectInterface::ROUTE_OBJECT, $route);
    $stack->push($request);
  }

  /**
   * Tests the stored maps leave the two entries out, and the merge fills them.
   */
  public function testTheMergeFollowsTheDefinitions(): void {
    $resources = $this->container->get('surface.resources');
    $context = $resources->operation(self::RESOURCE, 'configure');
    $built = $resources->build(self::RESOURCE, $context);
    $stored = array_intersect_key($resources->storage(self::RESOURCE, $context, $built)->load($built), $built->getDefinitions()->toArray());
    $this->assertSame(self::HELD, $stored);
    // One level deep, a stored map stood for its defaults whole.
    $this->assertSame(self::HELD['marks'], array_replace($built->getDefaultValues(), $stored)['marks']);
    $this->assertSame(self::READ, $built->withDefaults($stored));
    // A key nothing is stored for is its default whole.
    $this->assertSame([
      'hyphen' => MarkAction::REPLACE,
      'period' => MarkAction::REMOVE,
      'underscore' => MarkAction::REMOVE,
    ], $built->withDefaults([])['marks']);
  }

  /**
   * Tests the form draws each entry at its default and saves no null.
   */
  public function testTheFormDrawsAndSavesEachEntry(): void {
    $this->route();
    $state = new FormState();
    $container = $this->container->get('form_builder')->buildForm(SurfaceOperationForm::class, $state)[SurfaceOperationForm::SURFACE_KEY];
    $drawn = [];
    foreach (self::READ as $map => $entries) {
      foreach (array_keys($entries) as $id) {
        $drawn[$map][$id] = $container[$map][$id]['#default_value'] ?? NULL;
      }
    }
    $this->assertEquals(self::READ, $drawn);
    $this->assertTrue($container['types']['node']['#disabled'] ?? FALSE, 'Content is locked, and drawn fixed.');
    $this->assertNotContains(NULL, $drawn['marks'], 'No select is drawn on its empty option.');

    // Posted as drawn: every element, the locked one left out as a
    // browser leaves out a disabled checkbox.
    $state = new FormState();
    $state->setValues([
      SurfaceOperationForm::SURFACE_KEY => [
        // A programmed submission says unchecked with NULL.
        'types' => ['term' => $drawn['types']['term'] ? 1 : NULL, 'user' => $drawn['types']['user'] ? 1 : NULL],
        'marks' => array_map('strval', $drawn['marks']),
      ],
      'op' => 'Save',
    ]);
    $this->container->get('form_builder')->submitForm(SurfaceOperationForm::class, $state);
    $this->assertSame([], $state->getErrors());
    $saved = $this->config('surface_schema_test.aliases')->getRawData();
    $this->assertSame(['user'], $saved['types']);
    $this->assertNotContains(NULL, $saved['marks'], 'No entry is saved as null.');
    // A default storage never held is not written: the hyphen stays
    // unstored, and reads as its default.
    // Decision: see docs/decisions.md#absent-keeps-null-clears.
    $this->assertSame(self::HELD['marks'], $saved['marks']);
    $this->assertSame(self::READ, array_intersect_key($this->pipeline()->accept($this->aliases(), [], ['marks' => $saved['marks']] + self::HELD), self::READ));
  }

  /**
   * Tests a map alone in a group is drawn as the group's one details.
   */
  public function testTheMapAloneInItsGroupIsOneDetails(): void {
    $this->route();
    $state = new FormState();
    $form = $this->container->get('form_builder')->buildForm(SurfaceOperationForm::class, $state);
    $container = $form[SurfaceOperationForm::SURFACE_KEY];
    $group = $container[SurfaceFormBuilderInterface::GROUP_PREFIX . 'punctuation'];
    $this->assertSame('details', $group['#type']);
    $this->assertSame('Punctuation', (string) $group['#title']);
    $this->assertFalse($group['#open']);
    $this->assertSame('container', $container['marks']['#type']);
    $this->assertArrayNotHasKey('#title', $container['marks']);
    // A map in no group keeps its own details.
    $this->assertSame('details', $container['types']['#type']);
    $html = (string) $this->container->get('renderer')->renderInIsolation($form);
    $this->assertSame(1, substr_count($html, '<summary') - substr_count($html, 'Types with aliases</summary>'), 'One details is titled Punctuation.');
  }

  /**
   * Tests HTTP and the React page's contract read as the form does.
   */
  public function testEveryDoorReadsTheDefault(): void {
    // The envelope the React page draws from, and the one narrowed for no
    // values sent.
    $this->assertSame(self::READ, array_intersect_key($this->servedJson('GET', '/surface-api/' . self::RESOURCE)['values'], self::READ));
    $this->assertSame(self::READ, array_intersect_key($this->servedJson('POST', '/surface-api/' . self::RESOURCE . '/schema', [])['values'], self::READ));
    $served = $this->container->get('surface.served_operations');
    $this->assertSame(self::READ, array_intersect_key($served->current($served->resolve(self::RESOURCE, 'configure', [])), self::READ));

    // The pipeline accepts the defaults under what is stored.
    $this->assertSame(self::READ, array_intersect_key($this->pipeline()->accept($this->aliases(), [], self::HELD), self::READ));

    // The tools answer what storage holds, or would hold: an entry storage
    // holds nothing for is left out, never null, and holds its default at
    // every door that draws. A default storage never held is not written.
    // Decision: see docs/decisions.md#absent-keeps-null-clears.
    $tools = $this->container->get('plugin.manager.tool');
    $tool = $tools->createInstance('surface:' . self::RESOURCE . ':configure');
    $tool->setInputValue(OperationInputs::VALUES, ['marks' => ['hyphen' => MarkAction::REPLACE]]);
    $tool->setInputValue(OperationInputs::DRY_RUN, TRUE);
    $tool->execute();
    $this->assertTrue($tool->getResult()->isSuccess(), (string) $tool->getResult()->getMessage());
    $this->assertSame(self::HELD, array_intersect_key($tool->getResult()->getContextValues()[OperationInputs::VALUES], self::HELD));
    $read = $tools->createInstance('surface:' . self::RESOURCE . ':configure:read');
    $read->execute();
    $this->assertTrue($read->getResult()->isSuccess(), (string) $read->getResult()->getMessage());
    $this->assertSame(self::HELD, $read->getResult()->getContextValues()[OperationInputs::VALUES]);
  }

}
