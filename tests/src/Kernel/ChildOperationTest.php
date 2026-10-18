<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\Component\Render\PlainTextOutput;
use Drupal\Core\Routing\RouteObjectInterface;
use Drupal\data_surface\Contract\SchemaResolver;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\Form\DataSurfaceOperationForm;
use Drupal\data_surface\Pipeline\UnknownKeysException;
use Drupal\data_surface\Resource\OperationContext;
use Drupal\data_surface\ResourceBuild\Resources;
use Drupal\data_surface\ResourceBuild\ResourcesInterface;
use Drupal\data_surface_resource_test\Structure\Broken\MisnamingLarderStructure;
use Drupal\data_surface_resource_test\Structure\Broken\TwoDefaultsStructure;
use Drupal\data_surface_resource_test\Resource\Larder\LarderResource;
use Drupal\data_surface_resource_test\Resource\Larder\SpiceRackResource;
use Drupal\data_surface_resource_test\Resource\Pantry\LabelResource;
use Drupal\data_surface_resource_test\Resource\Pantry\PantryResource;
use Drupal\data_surface_resource_test\Structure\Pantry\ShelfStructure;
use Drupal\data_surface_resource_test\Storage\LarderStorage;
use Drupal\data_surface_tool\Plugin\tool\Tool\ResourceOperationTool;
use Drupal\data_surface_tool\OperationInputs;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\tool\Tool\ToolDefinition;
use Drupal\tool\Tool\ToolInterface;
use Drupal\tool\TypedData\MapInputDefinition;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests a child invoked through an operation of its own, and its access.
 *
 * The larder attaches the same spice rack twice. Editing it, the rack at
 * `rack` is invoked through the rack's own `edit`, by the context's id,
 * and the one at `spare_rack` through `restock`, which the larder's
 * operation names; adding one, both fall to the rack's default, `browse`;
 * stocking one, the larder hands both a context whole, built by calling
 * the rack's own `browse` and `restock`. The rack's access class answers
 * open, locked or omitted from three permissions however the rack was
 * invoked, and `restock` asks a permission of its own where the build
 * resolves it.
 *
 * Each answer is followed through every door: the built resource, both
 * contracts, the operation form, the write tool's `values` input, the
 * read tool, the pipeline, and what the larder's storage is left holding.
 *
 * @see \Drupal\data_surface_resource_test\Resource\Larder\LarderResource
 * @see \Drupal\data_surface_resource_test\Access\SpiceRackAccess
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class ChildOperationTest extends DataSurfaceKernelTestBase {

  use UserCreationTrait;

  /**
   * The write tool for the larder's edit operation.
   */
  protected const TOOL = 'data_surface:resource_test.larder:edit';

  /**
   * The read tool beside it.
   */
  protected const READ_TOOL = 'data_surface:resource_test.larder:edit:read';

  /**
   * What the larder holds before each test.
   */
  protected const STORED = [
    'larder' => 'main',
    'note' => 'Dry goods.',
    'rack' => ['shelf' => 'low', 'spices' => 7, 'label' => 'Everyday'],
    'spare_rack' => [
      'shelf' => 'top',
      'spices' => 2,
      'label' => 'Spare',
      'third_party_settings' => ['data_surface_resource_test' => ['supplier' => 'Acme']],
    ],
  ];

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'serialization',
    'tool',
    'data_surface',
    'data_surface_tool',
    'data_surface_resource_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    // The first account is the super user; every test acts as another.
    $this->setUpCurrentUser(admin: TRUE);
    $this->container->get('state')->set(LarderStorage::key('main'), self::STORED);
  }

  /**
   * Acts as an account that keeps a larder, with more permissions.
   *
   * @param string ...$permissions
   *   The spice rack permissions it holds.
   */
  protected function actAs(string ...$permissions): void {
    $this->setUpCurrentUser([], ['keep a larder', ...$permissions]);
  }

  /**
   * Gets the build step.
   *
   * @return \Drupal\data_surface\ResourceBuild\ResourcesInterface
   *   The service.
   */
  protected function resources(): ResourcesInterface {
    return $this->container->get('data_surface.resources');
  }

  /**
   * Builds the larder being edited, for the current account.
   *
   * @return \Drupal\data_surface\DataSurfaceInterface
   *   The larder.
   */
  protected function edit(): DataSurfaceInterface {
    return $this->resources()->buildOperation(LarderResource::class, 'edit', ['larder' => 'main']);
  }

  /**
   * Gets the child built at a key.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   The parent.
   * @param string $key
   *   The key.
   *
   * @return \Drupal\data_surface\DataSurfaceInterface
   *   The child.
   */
  protected static function child(DataSurfaceInterface $surface, string $key): DataSurfaceInterface {
    $attachment = $surface->getDefinitions()->entry($key)?->attachment;
    assert($attachment !== NULL);
    return $attachment->child;
  }

  /**
   * Builds the larder being stocked, both racks handed a context whole.
   *
   * @return \Drupal\data_surface\DataSurfaceInterface
   *   The larder.
   */
  protected function stock(): DataSurfaceInterface {
    return $this->resources()->buildOperation(LarderResource::class, 'stock', ['larder' => 'main']);
  }

  /**
   * Emits the larder's contract, as it stands or in full.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   The larder, built.
   * @param bool $full
   *   Whether to emit the full schema.
   * @param string $operation
   *   The larder's operation it was built in.
   *
   * @return \Drupal\data_surface\Contract\ServedContract
   *   The contract.
   */
  protected function contract(DataSurfaceInterface $surface, bool $full = FALSE, string $operation = 'edit'): mixed {
    $emitter = $this->container->get('data_surface.contract_emitter');
    if ($full) {
      return $emitter->emitFull($surface, 'resource_test.larder', $operation);
    }
    $context = $this->resources()->operation(LarderResource::class, $operation, ['larder' => 'main']);
    $stored = $this->resources()->storage(LarderResource::class, $context, $surface)->load($surface);
    return $emitter->emit($surface, array_replace($surface->getDefaultValues(), $stored), 'resource_test.larder', $operation);
  }

  /**
   * Builds the generic form at one of the larder's routes.
   *
   * @param string $operation
   *   The larder's operation the route serves: edit or stock.
   *
   * @return array
   *   The resource's part of the built form.
   */
  protected function form(string $operation = 'edit'): array {
    $name = 'data_surface_resource_test.larder.' . $operation;
    $route = $this->container->get('router.route_provider')->getRouteByName($name);
    $stack = $this->container->get('request_stack');
    $request = Request::create($operation === 'edit' ? '/surface-test/larder/main' : '/surface-test/larder/main/' . $operation);
    $request->setSession($stack->getSession());
    $request->attributes->set(RouteObjectInterface::ROUTE_NAME, $name);
    $request->attributes->set(RouteObjectInterface::ROUTE_OBJECT, $route);
    $request->attributes->set('larder', 'main');
    $request->attributes->set('_raw_variables', new InputBag(['larder' => 'main']));
    $stack->push($request);
    return $this->container->get('form_builder')->getForm(DataSurfaceOperationForm::class)[DataSurfaceOperationForm::SURFACE_KEY];
  }

  /**
   * Gets the write tool's `values` input, refined for the larder.
   *
   * @return \Drupal\tool\TypedData\MapInputDefinition
   *   The map.
   */
  protected function toolValues(): MapInputDefinition {
    $tool = $this->container->get('plugin.manager.tool')->createInstance(self::TOOL);
    assert($tool instanceof ResourceOperationTool);
    $definition = $tool->getPluginDefinition();
    $this->assertInstanceOf(ToolDefinition::class, $definition);
    $declared = $definition->getInputDefinition(OperationInputs::VALUES);
    $values = $tool->refineInputDefinition(OperationInputs::VALUES, $declared, ['larder' => 'main']);
    $this->assertInstanceOf(MapInputDefinition::class, $values);
    return $values;
  }

  /**
   * Runs one tool.
   *
   * @param string $id
   *   The plugin id.
   * @param array $inputs
   *   The inputs, by name.
   *
   * @return array{success: bool, message: string, values: mixed}
   *   Whether it succeeded, its message and its values output.
   */
  protected function runTool(string $id, array $inputs): array {
    $tool = $this->container->get('plugin.manager.tool')->createInstance($id);
    assert($tool instanceof ToolInterface);
    foreach ($inputs as $name => $value) {
      $tool->setInputValue($name, $value);
    }
    $tool->execute();
    $result = $tool->getResult();
    return [
      'success' => $result->isSuccess(),
      'message' => PlainTextOutput::renderFromHtml((string) $result->getMessage()),
      'values' => $result->getContextValues()[OperationInputs::VALUES] ?? NULL,
    ];
  }

  /**
   * Gets what the larder holds now.
   *
   * @return array
   *   The stored larder.
   */
  protected function stored(): array {
    return $this->container->get('state')->get(LarderStorage::key('main'));
  }

  /**
   * Tests the order a child's operation is resolved in.
   *
   * By name from the parent's operation, then by the parent context's id,
   * then the child's default; an alter naming one child operation applies
   * where that operation is invoked and nowhere else.
   */
  public function testResolutionOrder(): void {
    $this->actAs('stock spice rack');
    $edit = $this->edit();
    $rack = self::child($edit, 'rack');
    $spare = self::child($edit, 'spare_rack');

    // By the context's id: the rack's own edit, which knows no shelf.
    $this->assertSame(['shelf', 'spices', 'label'], $rack->getDefinitions()->names());
    $this->assertSame(['min' => 0, 'max' => 40], $rack->getDefinition('spices')->getConstraints()['Range']);
    $this->assertFalse($rack->isLocked('shelf'));

    // By name: restock, handed TOP, knows top, its own known key winning
    // over what it was handed; and only there the restock alter's
    // supplier.
    $this->assertSame(['shelf', 'spices', 'label', 'third_party_settings'], $spare->getDefinitions()->names());
    $this->assertTrue($spare->isLocked('shelf'));
    $this->assertSame('top', $spare->getDefault('shelf'));
    $this->assertSame(['min' => 0, 'max' => 40], $spare->getDefinition('spices')->getConstraints()['Range']);

    // The default: adding a larder names nothing, and the rack has no add.
    $add = $this->resources()->buildOperation(LarderResource::class, 'add');
    foreach (['rack', 'spare_rack'] as $key) {
      $child = self::child($add, $key);
      $this->assertSame(['shelf', 'spices', 'label'], $child->getDefinitions()->names(), $key);
      $this->assertSame(['min' => 0, 'max' => 10], $child->getDefinition('spices')->getConstraints()['Range'], $key);
    }

    // A context that knows no larder cannot invoke the rack's edit, which
    // needs one: the hint is passed over for the default.
    $bare = $this->resources()->build(LarderResource::class, new OperationContext('edit'));
    foreach (['rack', 'spare_rack'] as $key) {
      $this->assertSame(['min' => 0, 'max' => 10], self::child($bare, $key)->getDefinition('spices')->getConstraints()['Range'], $key);
    }

    // The access answer of a child reaches the parent's cacheability.
    $this->assertContains('user.permissions', $edit->getCacheContexts());
  }

  /**
   * Tests that a child with no operations is built exactly as before.
   *
   * The pantry's shelf and label declare none, so each is built in the
   * context Resources::childContext() hands it, the parent context's id and
   * identity, and, having no access class either, is asked nothing at
   * build.
   */
  public function testChildWithoutOperationsInherits(): void {
    $emitter = $this->container->get('data_surface.contract_emitter');
    $context = PantryResource::edit('cellar');
    $pantry = $this->resources()->build(PantryResource::class, $context);
    foreach (['shelf' => ShelfStructure::class, 'label' => LabelResource::class] as $key => $class) {
      $direct = $this->resources()->build($class, Resources::childContext($context, $key));
      $this->assertSame(
        $emitter->emit($direct, [], $key)->document,
        $emitter->emit(self::child($pantry, $key), [], $key)->document,
        $key,
      );
    }
    $this->assertNotContains('user.permissions', $emitter->emit($pantry, [], 'resource_test.pantry')->getCacheContexts());
  }

  /**
   * Tests an open child: every door as it always was.
   */
  public function testOpenChild(): void {
    $this->actAs('stock spice rack');
    $edit = $this->edit();
    $this->assertSame(['larder', 'note', 'rack', 'spare_rack'], $edit->getDefinitions()->names());
    $this->assertFalse($edit->isLocked('rack'));
    $this->assertFalse($edit->isLocked('spare_rack'));

    $schema = $this->contract($edit)->document['schema'];
    $this->assertFalse($schema['properties']['rack']['x-surface']['locked']);
    $this->assertArrayNotHasKey('readOnly', $schema['properties']['rack']);
    $rack = SchemaResolver::inline($schema['properties']['rack'], $schema);
    $this->assertFalse($rack['properties']['spices']['x-surface']['locked']);

    $form = $this->form();
    $this->assertArrayNotHasKey('#disabled', $form['rack']);
    $this->assertSame(7, $form['rack']['spices']['#default_value']);

    $values = $this->toolValues();
    $this->assertFalse($values->getPropertyDefinitions()['rack']->isLocked());

    $write = $this->runTool(self::TOOL, ['larder' => 'main', OperationInputs::VALUES => ['rack' => ['spices' => 9]]]);
    $this->assertTrue($write['success'], $write['message']);
    $this->assertSame(9, $this->stored()['rack']['spices']);
  }

  /**
   * Tests a locked child: shown everywhere, changeable nowhere.
   */
  public function testLockedChild(): void {
    // Stocking and inspecting: inspecting is the narrower answer, at both
    // keys, the restock operation's own permission being held.
    $this->actAs('stock spice rack', 'inspect spice rack');
    $edit = $this->edit();
    $this->assertSame(['larder', 'note', 'rack', 'spare_rack'], $edit->getDefinitions()->names());
    $this->assertTrue($edit->isLocked('rack'));
    $this->assertTrue($edit->isLocked('spare_rack'));

    // The contract as it stands: the fieldset and every key inside it
    // locked, each holding what is stored.
    $contract = $this->contract($edit);
    $schema = $contract->document['schema'];
    $this->assertTrue($schema['properties']['rack']['readOnly']);
    $this->assertTrue($schema['properties']['rack']['x-surface']['locked']);
    $rack = SchemaResolver::inline($schema['properties']['rack'], $schema);
    foreach (['shelf' => 'low', 'spices' => 7, 'label' => 'Everyday'] as $key => $value) {
      $this->assertTrue($rack['properties'][$key]['readOnly'], $key);
      $this->assertTrue($rack['properties'][$key]['x-surface']['locked'], $key);
      $this->assertSame($value, $rack['properties'][$key]['const'], $key);
    }
    $this->assertSame(7, $contract->document['values']['rack']['spices']);
    $this->assertContains('user.permissions', $contract->getCacheContexts());

    // The full schema: locked the same, and holding nothing, since it
    // reads no stored value.
    $full = $this->contract($edit, TRUE);
    $schema = $full->document['schema'];
    $this->assertTrue($schema['properties']['rack']['readOnly']);
    $this->assertTrue($schema['properties']['rack']['x-surface']['locked']);
    $rack = SchemaResolver::inline($schema['properties']['rack'], $schema);
    $this->assertTrue($rack['properties']['spices']['readOnly']);
    $this->assertArrayNotHasKey('const', $rack['properties']['spices']);
    $this->assertContains('user.permissions', $full->getCacheContexts());

    // The form: the fieldset drawn, disabled, and every element inside it
    // with it, showing what is stored.
    $form = $this->form();
    $this->assertTrue($form['rack']['#disabled']);
    $this->assertTrue($form['rack']['spices']['#disabled']);
    $this->assertSame(7, $form['rack']['spices']['#default_value']);
    $this->assertContains('user.permissions', $form['#cache']['contexts']);

    // The write tool's values: the map and every key in it locked.
    $values = $this->toolValues()->getPropertyDefinitions();
    $this->assertTrue($values['rack']->isLocked());
    $this->assertInstanceOf(MapInputDefinition::class, $values['rack']);
    $this->assertTrue($values['rack']->getPropertyDefinitions()['spices']->isLocked());

    // The read tool reads it.
    $read = $this->runTool(self::READ_TOOL, ['larder' => 'main']);
    $this->assertTrue($read['success'], $read['message']);
    $this->assertSame(self::STORED['rack'], $read['values']['rack']);

    // The pipeline ignores what is posted for it, as for any locked key.
    $accepted = $this->container->get('data_surface.pipeline')->accept($edit, ['rack' => ['spices' => 30]], self::STORED);
    $this->assertSame(self::STORED['rack'], $accepted['rack']);
    $write = $this->runTool(self::TOOL, [
      'larder' => 'main',
      OperationInputs::VALUES => ['note' => 'Moved.', 'rack' => ['spices' => 30]],
    ]);
    $this->assertTrue($write['success'], $write['message']);
    $stored = $this->stored();
    $this->assertSame('Moved.', $stored['note']);
    $this->assertSame(self::STORED['rack'], $stored['rack']);
    $this->assertSame(self::STORED['spare_rack'], $stored['spare_rack']);
  }

  /**
   * Tests an omitted child: absent everywhere, in both directions.
   */
  public function testOmittedChild(): void {
    $this->actAs('hide spice rack');
    $edit = $this->edit();
    $this->assertSame(['larder', 'note'], $edit->getDefinitions()->names());

    // The parent is still open to the account; only the children went.
    $context = $this->resources()->operation(LarderResource::class, 'edit', ['larder' => 'main']);
    $this->assertTrue($this->resources()->access(LarderResource::class, $context)->isAllowed());

    foreach ([FALSE, TRUE] as $full) {
      $contract = $this->contract($edit, $full);
      $this->assertSame(['larder', 'note'], array_keys($contract->document['schema']['properties']));
      $this->assertArrayNotHasKey('$defs', $contract->document['schema']);
      $this->assertContains('user.permissions', $contract->getCacheContexts());
    }

    $form = $this->form();
    $this->assertArrayHasKey('note', $form);
    $this->assertArrayNotHasKey('rack', $form);
    $this->assertArrayNotHasKey('spare_rack', $form);

    $this->assertSame(['note'], array_keys($this->toolValues()->getPropertyDefinitions()));

    // The read tool leaves it out: omitted is omitted both ways.
    $read = $this->runTool(self::READ_TOOL, ['larder' => 'main']);
    $this->assertTrue($read['success'], $read['message']);
    $this->assertSame(['note' => 'Dry goods.'], $read['values']);

    // A value posted for it is a key the built resource does not have.
    try {
      $this->container->get('data_surface.pipeline')->accept($edit, ['rack' => ['spices' => 30]], []);
      $this->fail('A value for an omitted child was accepted.');
    }
    catch (UnknownKeysException $e) {
      $this->assertSame(['rack'], $e->getKeys());
    }
    $refused = $this->runTool(self::TOOL, [
      'larder' => 'main',
      OperationInputs::VALUES => ['rack' => ['spices' => 30]],
    ]);
    $this->assertFalse($refused['success']);

    // A write leaves what it could not see as it was.
    $write = $this->runTool(self::TOOL, ['larder' => 'main', OperationInputs::VALUES => ['note' => 'Moved.']]);
    $this->assertTrue($write['success'], $write['message']);
    $stored = $this->stored();
    $this->assertSame('Moved.', $stored['note']);
    $this->assertSame(self::STORED['rack'], $stored['rack']);
    $this->assertSame(self::STORED['spare_rack'], $stored['spare_rack']);
  }

  /**
   * Tests core's answers, the operation's permission, and the top level.
   */
  public function testCoreAnswersAndPermissions(): void {
    // No rack permission at all: the access class answers core's
    // forbidden, which at a child is omitted; restock's permission is not
    // held either.
    $this->actAs();
    $this->assertSame(['larder', 'note'], $this->edit()->getDefinitions()->names());

    // Inspecting alone: the rack is locked by its access class, the spare
    // rack omitted by the restock operation's permission before its class
    // is asked.
    $this->actAs('inspect spice rack');
    $edit = $this->edit();
    $this->assertSame(['larder', 'note', 'rack'], $edit->getDefinitions()->names());
    $this->assertTrue($edit->isLocked('rack'));

    // Asked on its own, the rack's locked answer is a refusal: a whole
    // resource shown and not saved is not built at the top level.
    $this->assertTrue($this->resources()->access(SpiceRackResource::class, SpiceRackResource::browse())->isForbidden());
    $this->actAs('stock spice rack');
    $this->assertTrue($this->resources()->access(SpiceRackResource::class, SpiceRackResource::browse())->isAllowed());
  }

  /**
   * Tests a context handed whole: its access class answers, no permission.
   *
   * Stocking, the larder hands both racks a context it built itself, by
   * calling the rack's `browse` and `restock`. No operation of the rack's
   * is invoked, so `restock`'s permission is not asked: the larder took
   * responsibility for entering it. The rack's access class answers
   * exactly as on a resolved path, open, locked or omitted, and what it
   * read reaches the larder's contracts and form.
   */
  public function testHandedContextIsAnsweredByItsAccessClass(): void {
    // Inspecting alone, without restock's permission. Editing resolves
    // restock by name and omits the spare rack for want of it; stocking
    // hands restock whole, and both racks are locked by the access class.
    $this->actAs('inspect spice rack');
    $this->assertSame(['larder', 'note', 'rack'], $this->edit()->getDefinitions()->names());
    $stock = $this->stock();
    $this->assertSame(['larder', 'note', 'rack', 'spare_rack'], $stock->getDefinitions()->names());
    $this->assertTrue($stock->isLocked('rack'));
    $this->assertTrue($stock->isLocked('spare_rack'));
    // Each handed context is taken as it is: browse's smaller range, and
    // the shelf restock knows, locked.
    $this->assertSame(['min' => 0, 'max' => 10], self::child($stock, 'rack')->getDefinition('spices')->getConstraints()['Range']);
    $spare = self::child($stock, 'spare_rack');
    $this->assertTrue($spare->isLocked('shelf'));
    $this->assertSame('top', $spare->getDefault('shelf'));

    // What the answer read reaches the parent and everything emitted.
    $this->assertContains('user.permissions', $stock->getCacheContexts());
    foreach ([FALSE, TRUE] as $full) {
      $contract = $this->contract($stock, $full, 'stock');
      $schema = $contract->document['schema'];
      $this->assertTrue($schema['properties']['rack']['readOnly']);
      $this->assertTrue($schema['properties']['spare_rack']['x-surface']['locked']);
      $this->assertContains('user.permissions', $contract->getCacheContexts());
    }
    $form = $this->form('stock');
    $this->assertTrue($form['rack']['#disabled']);
    $this->assertTrue($form['spare_rack']['#disabled']);
    $this->assertContains('user.permissions', $form['#cache']['contexts']);

    // A child's answer shapes its own keys and refuses nothing above it.
    $context = $this->resources()->operation(LarderResource::class, 'stock', ['larder' => 'main']);
    $this->assertTrue($this->resources()->access(LarderResource::class, $context)->isAllowed());

    // Hiding: both racks omitted, from the contracts and the form too.
    $this->actAs('hide spice rack');
    $stock = $this->stock();
    $this->assertSame(['larder', 'note'], $stock->getDefinitions()->names());
    foreach ([FALSE, TRUE] as $full) {
      $contract = $this->contract($stock, $full, 'stock');
      $this->assertSame(['larder', 'note'], array_keys($contract->document['schema']['properties']));
      $this->assertContains('user.permissions', $contract->getCacheContexts());
    }
    $form = $this->form('stock');
    $this->assertArrayNotHasKey('rack', $form);
    $this->assertArrayNotHasKey('spare_rack', $form);
    $this->assertContains('user.permissions', $form['#cache']['contexts']);
    $this->assertTrue($this->resources()->access(LarderResource::class, $context)->isAllowed());

    // No rack permission at all: core's forbidden, omitted at a child.
    $this->actAs();
    $this->assertSame(['larder', 'note'], $this->stock()->getDefinitions()->names());

    // Stocking: both open.
    $this->actAs('stock spice rack');
    $stock = $this->stock();
    $this->assertSame(['larder', 'note', 'rack', 'spare_rack'], $stock->getDefinitions()->names());
    $this->assertFalse($stock->isLocked('rack'));
    $this->assertFalse($stock->isLocked('spare_rack'));
  }

  /**
   * Tests the two refusals: two defaults, and an operation not declared.
   */
  public function testRefusals(): void {
    try {
      $this->resources()->build(TwoDefaultsStructure::class, new OperationContext('first'));
      $this->fail('Two default operations were accepted.');
    }
    catch (\LogicException $e) {
      $this->assertStringStartsWith('resource_test.broken.two_defaults marks two operations the default, "first" from ' . TwoDefaultsStructure::class . '::first() in data_surface_resource_test and "second" from ' . TwoDefaultsStructure::class . '::second()', $e->getMessage());
    }

    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage('resource_test.broken.misnaming_larder, in its "clean" context, names the "dust" operation for its "rack" child, which resource_test.larder.rack does not declare; it declares edit, browse, restock.');
    $this->resources()->buildOperation(MisnamingLarderStructure::class, 'clean');
  }

}
