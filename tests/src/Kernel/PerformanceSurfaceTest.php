<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\Component\Render\MarkupInterface;
use Drupal\Component\Utility\Html;
use Drupal\Core\Form\FormState;
use Drupal\Core\Routing\RouteObjectInterface;
use Drupal\data_surface\Form\DataSurfaceFormBuilderInterface;
use Drupal\data_surface\Form\DataSurfaceOperationForm;
use Drupal\data_surface_performance\Resource\PerformanceResource;
use Drupal\data_surface_tool\OperationInputs;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests the performance settings, stored in core's system.performance.
 *
 * The resource declares the title, the description and the list of
 * periods core's performance form gives the page cache's maximum age; the
 * form, the tool and the OpenAPI document read them from it, and the
 * storage holds it to core's config schema as core wrote it, writing each
 * key at core's path and keeping the settings it does not declare.
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class PerformanceSurfaceTest extends DataSurfaceKernelTestBase {

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
    'data_surface_react',
    'data_surface_openapi',
    'data_surface_performance',
  ];

  /**
   * The resource's id.
   */
  protected const ID = 'system.performance';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installConfig(['system']);
    $this->container->get('router.builder')->rebuild();
    $this->setUpCurrentUser(admin: TRUE);
  }

  /**
   * Gives the periods core's performance form offers, labeled as it does.
   *
   * @return array<int, string>
   *   Each label as core's form renders it, by the period in seconds.
   */
  protected function coreLabels(): array {
    $formatter = $this->container->get('date.formatter');
    $periods = [0, 60, 180, 300, 600, 900, 1800, 2700, 3600, 10800, 21600, 32400, 43200, 86400];
    $labels = array_map([$formatter, 'formatInterval'], array_combine($periods, $periods));
    $labels[0] = '<' . $this->container->get('string_translation')->translate('no caching') . '>';
    return array_map(static fn (string|\Stringable $label): string => Html::escape((string) $label), $labels);
  }

  /**
   * Runs the configure tool.
   *
   * @param array $values
   *   The values to send.
   *
   * @return array
   *   The result's context values.
   */
  protected function configure(array $values): array {
    $tool = $this->container->get('plugin.manager.tool')->createInstance(OperationInputs::TOOL . ':' . self::ID . ':configure');
    $tool->setInputValue(OperationInputs::VALUES, $values);
    $tool->execute();
    return $tool->getResult()->getContextValues();
  }

  /**
   * Tests the resource is held to core's schema, which refuses nothing.
   */
  public function testHeldToCoresSchema(): void {
    $resources = $this->container->get('data_surface.resources');
    $built = $resources->build(self::ID, $resources->operation(self::ID, 'configure'));
    $this->assertSame([], $resources->storageInstance(self::ID)->widerThanSchema($built));
    $this->assertSame(PerformanceResource::PERIODS, array_keys($this->coreLabels()));
  }

  /**
   * Tests the form is core's form: the select, its title and its options.
   */
  public function testTheFormIsCoresForm(): void {
    $route_name = 'data_surface_performance.configure';
    $route = $this->container->get('router.route_provider')->getRouteByName($route_name);
    $stack = $this->container->get('request_stack');
    $request = Request::create($route->getPath());
    $request->setSession($stack->getSession());
    $request->attributes->set(RouteObjectInterface::ROUTE_NAME, $route_name);
    $request->attributes->set(RouteObjectInterface::ROUTE_OBJECT, $route);
    $stack->push($request);
    $form_state = new FormState();
    $form = $this->container->get('form_builder')->buildForm(DataSurfaceOperationForm::class, $form_state)[DataSurfaceOperationForm::SURFACE_KEY];

    $max_age = $form['max_age'];
    $this->assertSame('select', $max_age['#type']);
    $this->assertSame('Browser and proxy cache maximum age', (string) $max_age['#title']);
    $this->assertStringContainsString('This is used as the value for max-age in Cache-Control headers.', (string) $max_age['#description']);
    $rendered = array_map(static fn (string|\Stringable $label): string => $label instanceof MarkupInterface ? (string) $label : Html::escape((string) $label), $max_age['#options']);
    $this->assertSame($this->coreLabels(), $rendered, 'Rendered as core renders its options, <no caching> included.');

    $this->assertSame('checkbox', $form['css_preprocess']['#type']);
    $this->assertSame('Aggregate CSS files', (string) $form['css_preprocess']['#title']);
    $this->assertSame('checkbox', $form['js_preprocess']['#type']);
    $this->assertSame('Aggregate JavaScript files', (string) $form['js_preprocess']['#title']);

    $caching = $form[DataSurfaceFormBuilderInterface::GROUP_PREFIX . 'caching'];
    $this->assertSame(['details', 'Caching'], [$caching['#type'], (string) $caching['#title']]);
    $bandwidth = $form[DataSurfaceFormBuilderInterface::GROUP_PREFIX . 'bandwidth_optimization'];
    $this->assertSame('Bandwidth optimization', (string) $bandwidth['#title']);
    $this->assertSame('External resources can be optimized automatically, which can reduce both the size and number of requests made to your website.', (string) $bandwidth['#description']);
  }

  /**
   * Tests a write lands at core's paths and keeps what it does not declare.
   */
  public function testWriteKeepsWhatItDoesNotDeclare(): void {
    $before = $this->config('system.performance')->getRawData();
    $result = $this->configure(['max_age' => 300, 'css_preprocess' => FALSE, 'js_preprocess' => TRUE]);
    $this->assertTrue($result['committed'], json_encode($result['violations']));

    $after = $this->config('system.performance')->getRawData();
    $this->assertSame(300, $after['cache']['page']['max_age']);
    $this->assertFalse($after['css']['preprocess']);
    $this->assertTrue($after['js']['preprocess']);
    $this->assertSame($before['css']['compress'], $after['css']['compress']);
    $this->assertSame($before['js']['compress'], $after['js']['compress']);
    $this->assertSame($before['fast_404'], $after['fast_404']);

    $refused = $this->configure(['max_age' => 120]);
    $this->assertFalse($refused['ok']);
    $this->assertSame(['/max_age'], array_column($refused['violations'], 'path'));
    $this->assertSame(['no_such_choice'], array_column($refused['violations'], 'code'));

    $refused = $this->configure(['max_age' => NULL]);
    $this->assertFalse($refused['ok']);
    $this->assertSame(['/max_age'], array_column($refused['violations'], 'path'), 'Required: null does not clear it.');
    $this->assertSame(300, $this->config('system.performance')->get('cache.page.max_age'));
  }

  /**
   * Tests the tool and the OpenAPI document state the list and its labels.
   */
  public function testTheToolAndTheDocumentStateTheList(): void {
    $labels = array_map(static fn (string $label): string => Html::decodeEntities($label), $this->coreLabels());

    $definition = $this->container->get('plugin.manager.tool')->getDefinition(OperationInputs::TOOL . ':' . self::ID . ':configure');
    $max_age = $definition->getInputDefinition(OperationInputs::VALUES)->getPropertyDefinitions()['max_age'];
    $this->assertSame('integer', $max_age->getDataType());
    $this->assertSame('Browser and proxy cache maximum age', (string) $max_age->getLabel());
    $this->assertStringStartsWith('This is used as the value for max-age in Cache-Control headers.', (string) $max_age->getDescription());
    $constraints = $max_age->getConstraints();
    $this->assertSame(PerformanceResource::PERIODS, $constraints['Choice']['choices']);
    $this->assertSame($labels, $constraints['LabeledChoice']['labels']);

    $document = json_decode((string) json_encode($this->container->get('data_surface_openapi.document')->build(Request::create('https://example.com/surface-api/openapi.json'))->document, JSON_THROW_ON_ERROR), TRUE);
    $this->assertArrayHasKey('/surface-api/system.performance', $document['paths']);
    $schema = $document['components']['schemas']['system.performance.configure']['properties']['max_age'];
    $this->assertSame('integer', $schema['type']);
    $this->assertSame('Browser and proxy cache maximum age', $schema['title']);
    $this->assertSame('This is used as the value for max-age in Cache-Control headers.', $schema['description']);
    $this->assertSame($labels, array_column($schema['oneOf'], 'title', 'const'));
  }

}
