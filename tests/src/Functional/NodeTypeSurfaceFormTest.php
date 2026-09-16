<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Functional;

use Behat\Mink\Driver\BrowserKitDriver;
use Drupal\Tests\BrowserTestBase;
use Drupal\node\Entity\NodeType;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the surface-driven content type add and edit forms in a browser.
 *
 * The demo's largest claim is that one declaration serves two operations
 * and that the operation is build-time context: uniqueness is added on
 * add, the machine name is locked on edit, and a composite target writes
 * a config entity and a set of base field overrides in one commit. All
 * of that had been exercised by calling the provider and the pipeline.
 * What only a browser shows is that the form really redirects and says
 * so, that a locked key is not merely marked disabled but is refused
 * when a client sends it anyway, and that the uniqueness constraint —
 * the one check static schema cannot express — reaches the person as an
 * error on the element that carries the machine name.
 *
 * This checkout cannot run this test locally: installing the node module
 * inside a functional test fails here with a PluginNotFoundException for
 * node_make_sticky_action, and core's own node functional tests fail
 * identically, so the failure is the environment rather than this
 * module. It is written for CI.
 *
 * @group data_surface
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class NodeTypeSurfaceFormTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'node',
    'data_surface',
    'data_surface_demo_node_type',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * The route the surface-driven add form is served at.
   */
  protected const ADD_ROUTE = 'admin/structure/types/surface-add';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // Both requirements on the routes: core's own content type access,
    // and the module's own restricted permission. Granting one without
    // the other is the case the routing file exists to describe.
    $this->drupalLogin($this->drupalCreateUser([
      'administer content types',
      'administer data surface node type demo',
    ]));
  }

  /**
   * Tests adding a content type through the generated form.
   */
  public function testAddStoresTheTypeAndItsOverrides(): void {
    $assert_session = $this->assertSession();
    $this->drupalGet(self::ADD_ROUTE);
    $assert_session->statusCodeEquals(200);

    // Every declared key reached the page at the value path the form
    // nests its surface at, including the three that are not stored on
    // the content type at all.
    $assert_session->fieldExists('surface[name]');
    $assert_session->fieldExists('surface[type]');
    $assert_session->fieldExists('surface[title_label]');
    $assert_session->fieldExists('surface[preview_mode]');
    // The declaration's defaults are the form's values.
    $assert_session->fieldValueEquals('surface[title_label]', 'Title');
    $assert_session->checkboxChecked('surface[status]');
    $assert_session->checkboxNotChecked('surface[promote]');

    $this->submitForm([
      'surface[name]' => 'Recipe',
      'surface[type]' => 'recipe',
      'surface[description]' => 'Cooking instructions.',
      'surface[title_label]' => 'Recipe name',
      'surface[preview_mode]' => '2',
      'surface[promote]' => TRUE,
    ], 'Save');

    // The message names the type, and the form redirects to the
    // collection rather than staying where it was.
    $assert_session->pageTextContains('The content type Recipe has been added.');
    $assert_session->addressEquals('admin/structure/types');

    // The config entity half of the composite target.
    $type = NodeType::load('recipe');
    $this->assertInstanceOf(NodeType::class, $type);
    $this->assertSame('Recipe', $type->label());
    $this->assertSame('Cooking instructions.', $type->getDescription());
    $this->assertSame(2, $type->getPreviewMode(FALSE)->value);

    // And the base field override half: the title's per bundle label and
    // the promote default are not stored on the content type, and both
    // moved in the same commit.
    $fields = $this->container->get('entity_field.manager')
      ->getFieldDefinitions('node', 'recipe');
    $this->assertSame('Recipe name', (string) $fields['title']->getLabel());
    $this->assertTrue((bool) $fields['promote']->getDefaultValueLiteral()[0]['value']);
  }

  /**
   * Tests that a machine name already in use is refused on its element.
   */
  public function testDuplicateMachineNameIsRefusedOnTheTypeElement(): void {
    $assert_session = $this->assertSession();
    NodeType::create(['type' => 'recipe', 'name' => 'Recipe'])->save();

    $this->drupalGet(self::ADD_ROUTE);
    $this->submitForm([
      'surface[name]' => 'Second recipe',
      'surface[type]' => 'recipe',
      'surface[title_label]' => 'Title',
    ], 'Save');

    // The constraint consults storage, which no config schema can do,
    // and its message names the value that was refused.
    $assert_session->pageTextContains('A content type with the machine name recipe already exists.');
    $assert_session->pageTextNotContains('has been added.');
    // Flagged on the element that carries the machine name, not on the
    // form as a whole.
    $assert_session->elementExists('css', 'input[name="surface[type]"].error');
    // Nothing was written: the type that already existed is untouched.
    $this->assertSame('Recipe', NodeType::load('recipe')->label());
  }

  /**
   * Tests that the machine name is locked on edit, POST and all.
   */
  public function testEditLocksTheMachineName(): void {
    $assert_session = $this->assertSession();
    NodeType::create(['type' => 'recipe', 'name' => 'Recipe'])->save();
    $path = 'admin/structure/types/manage/recipe/surface-edit';

    $this->drupalGet($path);
    $assert_session->statusCodeEquals(200);
    // Still advertised, and its value shown, so a person sees the key
    // and what it holds. Locking narrows the value space to one value;
    // it does not hide the key.
    $assert_session->fieldValueEquals('surface[type]', 'recipe');
    $assert_session->fieldDisabled('surface[type]');
    // And the reason is in the description, where a screen reader
    // reaches it rather than only in the disabled attribute.
    $assert_session->pageTextContains('Fixed for this operation.');

    // A browser does not submit a disabled element at all. A client that
    // sends one anyway is the case the lock exists for, so the POST is
    // built by hand rather than through the page.
    $submit_xpath = $assert_session->buttonExists('Save')->getXpath();
    $driver = $this->getSession()->getDriver();
    $this->assertInstanceOf(BrowserKitDriver::class, $driver);
    $client = $driver->getClient();
    $form = $client->getCrawler()->filterXPath($submit_xpath)->form();
    $values = $form->getPhpValues();
    $this->assertArrayNotHasKey('type', $values['surface']);
    $values['surface']['name'] = 'Renamed';
    $values['surface']['type'] = 'tampered';
    $client->request($form->getMethod(), $form->getUri(), $values);

    // The rename went through; the machine name did not move, and no
    // second content type was created under the name that was sent.
    $assert_session->pageTextContains('The content type Renamed has been updated.');
    $this->assertNull(NodeType::load('tampered'));
    $renamed = NodeType::load('recipe');
    $this->assertInstanceOf(NodeType::class, $renamed);
    $this->assertSame('Renamed', $renamed->label());
  }

}
