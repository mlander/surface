<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\Core\Routing\RouteObjectInterface;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\Form\DataSurfaceOperationForm;
use Drupal\data_surface\Variants\ComponentProps;
use Drupal\data_surface\Variants\ComponentSlots;
use Drupal\data_surface\Widget\DataSurfaceWidgetBase;
use Drupal\data_surface_examples\Resource\SpotlightResource;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests the components a site offers where a key chooses one.
 *
 * `data_surface.settings:components_offered` lists component ids and
 * providers; empty, every component is offered. The props and the slots
 * lists read it in one place, so both offer the same components, and
 * both carry the setting's cache tag. A stored component it leaves out
 * is kept as stored.
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class ComponentsOfferedTest extends DataSurfaceKernelTestBase {

  use UserCreationTrait;

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
    'data_surface_examples',
    'data_surface_widget_test',
  ];

  /**
   * The examples module's components, in the order of their ids.
   */
  protected const EXAMPLES = [
    'data_surface_examples:call_to_action',
    'data_surface_examples:card',
    'data_surface_examples:hero',
    'data_surface_examples:section',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installConfig(['data_surface', 'data_surface_examples']);
    $this->setUpCurrentUser(admin: TRUE);
  }

  /**
   * Sets what the site offers.
   *
   * @param string[] $entries
   *   Component ids and providers.
   */
  protected function offer(array $entries): void {
    $this->config('data_surface.settings')->set('components_offered', $entries)->save();
  }

  /**
   * Gets the ids each list offers, the props' and the slots'.
   *
   * @return array{0: string[], 1: string[]}
   *   The ids the props list offers, and the ids the slots list offers.
   */
  protected function offered(): array {
    $resolver = $this->container->get('class_resolver');
    return [
      array_keys($resolver->getInstanceFromDefinition(ComponentProps::class)->list()->entries()),
      array_keys($resolver->getInstanceFromDefinition(ComponentSlots::class)->list()->entries()),
    ];
  }

  /**
   * Builds example 12's spotlight.
   *
   * @return \Drupal\data_surface\DataSurfaceInterface
   *   The built resource.
   */
  protected function spotlight(): DataSurfaceInterface {
    $resources = $this->container->get('data_surface.resources');
    return $resources->build(SpotlightResource::class, $resources->operation(SpotlightResource::class, 'configure'));
  }

  /**
   * Tests an allowlist of a provider and of one id, in both lists.
   */
  public function testTheListsOfferWhatTheSettingAllows(): void {
    $resolver = $this->container->get('class_resolver');
    [$props, $slots] = $this->offered();
    $this->assertSame($props, $slots);
    $this->assertSame([...self::EXAMPLES, 'data_surface_widget_test:mixed', 'data_surface_widget_test:teaser'], array_values(array_filter($props, static fn (string $id): bool => str_starts_with($id, 'data_surface_'))), 'Empty: every component the site has.');
    foreach ([ComponentProps::class, ComponentSlots::class] as $class) {
      $this->assertContains('config:data_surface.settings', $resolver->getInstanceFromDefinition($class)->list()->getCacheTags());
      $this->assertContains('config:core.extension', $resolver->getInstanceFromDefinition($class)->list()->getCacheTags());
    }

    // A provider: every component it provides.
    $this->offer(['data_surface_examples']);
    $this->assertSame([self::EXAMPLES, self::EXAMPLES], $this->offered());
    $slots = $resolver->getInstanceFromDefinition(ComponentSlots::class);
    $this->assertSame(self::EXAMPLES, $slots->expected(['title' => 'Anything']), 'A slot that expects nothing in particular offers what the site offers.');
    $this->assertSame(['data_surface_examples:call_to_action'], $slots->expected($slots->metadata('data_surface_examples:hero')['actions']), 'A slot that names its components keeps them.');
    $component = $this->spotlight()->getDefinition('component');
    $this->assertSame(self::EXAMPLES, array_map('strval', array_keys($this->container->get('data_surface.options')->resolve($component)->options)), 'The chooser offers what the lists offer.');

    // One id, beside a provider.
    $teaser = 'data_surface_widget_test:teaser';
    $this->offer([$teaser, 'data_surface_examples']);
    $this->assertSame([[...self::EXAMPLES, $teaser], [...self::EXAMPLES, $teaser]], $this->offered());
    $this->offer([$teaser]);
    $this->assertSame([[$teaser], [$teaser]], $this->offered());
  }

  /**
   * Tests a stored component the setting leaves out is kept as stored.
   *
   * The chooser stands for it on its empty option, and a save that leaves
   * it alone writes the component and its props back as they were.
   */
  public function testStoredComponentLeftOutIsKept(): void {
    $this->offer(['data_surface_widget_test']);
    $stored = $this->config('data_surface_examples.spotlight')->get();
    $route = $this->container->get('router.route_provider')->getRouteByName('data_surface_examples.step12');
    $stack = $this->container->get('request_stack');
    $request = Request::create($route->getPath());
    $request->setSession($stack->getSession());
    $request->attributes->set(RouteObjectInterface::ROUTE_NAME, 'data_surface_examples.step12');
    $request->attributes->set(RouteObjectInterface::ROUTE_OBJECT, $route);
    $stack->push($request);
    $form = $this->container->get('form_builder')->getForm(DataSurfaceOperationForm::class);
    $component = $form[DataSurfaceOperationForm::SURFACE_KEY]['component'];
    $this->assertSame('data_surface_examples:card', $component[DataSurfaceWidgetBase::STALE_KEY]);
    $this->assertSame(['', 'data_surface_widget_test:mixed', 'data_surface_widget_test:teaser'], array_map('strval', array_keys($component['#options'])));

    $form_state = new FormState();
    $form_state->setValues([DataSurfaceOperationForm::SURFACE_KEY => []]);
    $this->container->get('form_builder')->submitForm(DataSurfaceOperationForm::class, $form_state);
    $this->assertSame([], $form_state->getErrors());
    $this->assertSame($stored, $this->config('data_surface_examples.spotlight')->get());
    // Read back as stored, as the read tool answers it.
    $this->assertSame(array_diff_key($stored, ['_core' => TRUE]), $this->pipeline()->outbound($this->spotlight(), array_diff_key($stored, ['_core' => TRUE])));
  }

  /**
   * Tests the post-update adds the setting, empty, to an existing site.
   */
  public function testThePostUpdateOffersEveryComponent(): void {
    $raw = $this->config('data_surface.settings')->getRawData();
    unset($raw['components_offered']);
    $this->container->get('config.storage')->write('data_surface.settings', $raw);
    $this->container->get('config.factory')->reset('data_surface.settings');
    $this->assertNull($this->config('data_surface.settings')->get('components_offered'));
    require_once dirname(__DIR__, 3) . '/data_surface.post_update.php';
    data_surface_post_update_components_offered();
    $this->assertSame([], $this->config('data_surface.settings')->get('components_offered'));
  }

}
