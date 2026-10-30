<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\surface\SurfaceBuilder;
use Drupal\surface\SurfaceInterface;
use Drupal\surface\SurfaceRefinerInterface;
use Drupal\surface\Pipeline\Sentences;
use Drupal\surface\Refinement\DoesNotApply;
use Drupal\surface\Resource\OperationContext;
use Drupal\surface_examples\Resource\WebhookResource;
use Drupal\surface_resource_test\Resource\Parcel\ParcelResource;
use Drupal\surface_resource_test\Structure\Crate\CrateStructure;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests a condition names the key it reads by its label, at every depth.
 *
 * By its label; a key another holds (a key an alter mounted, a key of a
 * variant) by the label of what holds it, a colon, then its own; a key
 * with no label by its JSON Pointer. The form's rendering and the
 * machine's say the same, and the refused key's pointer stays the path.
 *
 * @see docs/decisions.md#a-refusal-names-what-it-depends-on
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class RefusalLabelsTest extends SurfaceKernelTestBase {

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
    'surface',
    'surface_tool',
    'surface_resource_test',
    'surface_examples',
    'surface_examples_keys',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installConfig(['surface_resource_test', 'surface_examples', 'surface_examples_keys']);
    $this->setUpCurrentUser(admin: TRUE);
  }

  /**
   * Validates values, and says each refusal both ways.
   *
   * @param \Drupal\surface\SurfaceInterface $surface
   *   The built resource or structure.
   * @param array $values
   *   What is sent.
   * @param array $stored
   *   What storage holds.
   *
   * @return array<string, array{0: string, 1: string}>
   *   By the refused key's dotted path: the form's rendering, then the
   *   machine's, as plain text.
   */
  protected function refusals(SurfaceInterface $surface, array $values, array $stored = []): array {
    $said = [];
    $accepted = $this->pipeline()->accept($surface, $values, $stored);
    foreach ($this->pipeline()->validate($surface, $accepted, $stored) as $violation) {
      $said[$violation->fullPath()] = [
        html_entity_decode(strip_tags((string) $violation->message), ENT_QUOTES),
        html_entity_decode(strip_tags(Sentences::machine($violation->message)), ENT_QUOTES),
      ];
    }
    return $said;
  }

  /**
   * Tests the parcel's customs code is named with its mount's label.
   *
   * The alter mounts the code at
   * `third_party_settings.surface_resource_test.customs`, a map
   * titled with the module's name.
   */
  public function testTheParcelsCustomsCodeIsNamedUnderItsMount(): void {
    $resources = $this->container->get('surface.resources');
    $parcel = $resources->build(ParcelResource::class, $resources->operation(ParcelResource::class, 'configure'));
    $stored = $this->config('surface_resource_test.parcel')->getRawData();
    $customs = ['third_party_settings' => ['surface_resource_test' => ['customs' => 'C1']]];
    $sentence = 'This value should be between 1 and 100000. This applies when all 4 of these hold: Weight in kilograms is 2; Reference is set; Insured is off; Surface API Resource Test: Customs code is set.';
    $this->assertSame(
      ['declared_value' => [$sentence, $sentence]],
      $this->refusals($parcel, ['weight' => 2, 'declared_value' => 0, 'reference' => 'R1'] + $customs, $stored),
    );
  }

  /**
   * Tests example 14's webhook names the managed key by its mount's label.
   */
  public function testTheWebhookNamesTheManagedKeyUnderItsMount(): void {
    $resources = $this->container->get('surface.resources');
    $webhook = $resources->build(WebhookResource::class, $resources->operation(WebhookResource::class, 'configure'));
    $sent = [
      'api_key' => 'wh-1',
      'third_party_settings' => ['surface_examples_keys' => ['key_id' => 'webhook_signing']],
    ];
    $sentence = 'API key does not apply when Managed key: Key is set.';
    $this->assertSame(['api_key' => [$sentence, $sentence]], $this->refusals($webhook, $sent));

    // The tool's answer: the same sentence, the refused key by pointer.
    $tool = $this->container->get('plugin.manager.tool')->createInstance('surface:event.webhook:configure');
    $tool->setInputValue('values', $sent);
    $tool->setInputValue('dry_run', TRUE);
    $tool->execute();
    $violations = $tool->getResult()->getContextValues()['violations'];
    $this->assertSame([['path' => '/api_key', 'code' => 'does_not_apply', 'message' => $sentence]], $violations);
  }

  /**
   * Tests a key of a variant is named with the label of the key holding it.
   */
  public function testVariantKeyIsNamedUnderItsHolder(): void {
    $crate = $this->container->get('surface.resources')->build(CrateStructure::class, new OperationContext('configure'));
    $sentence = 'This value should be between 1 and 2. This applies when Contents settings: Fruit is peach.';
    $this->assertSame(
      ['contents_settings.layers' => [$sentence, $sentence]],
      $this->refusals($crate, ['contents' => 'fruit', 'contents_settings' => ['fruit' => 'peach', 'layers' => 4]]),
    );
  }

  /**
   * Tests a mounted key with no label is named by its JSON Pointer.
   */
  public function testKeyWithNoLabelIsNamedByItsPointer(): void {
    $builder = new SurfaceBuilder();
    $builder->setDefinition('note', DataDefinition::create('string')->setLabel('Note'));
    $builder->setThirdPartyDefinition('surface_resource_test', 'flag', DataDefinition::create('string'));
    $flag = 'third_party_settings.surface_resource_test.flag';
    $builder->addRefinement('note', [$flag]);
    $builder->addRefiner('note', new class ($flag) implements SurfaceRefinerInterface {

      /**
       * Constructs the refiner.
       *
       * @param string $flag
       *   The watched key's path.
       */
      public function __construct(protected readonly string $flag) {}

      /**
       * {@inheritdoc}
       */
      public function refineDataDefinition(string $name, DataDefinitionInterface $definition, array $values): DataDefinitionInterface {
        // The watched values, each by its path.
        return ($values[$this->flag] ?? NULL) === NULL ? $definition : DoesNotApply::marked($definition);
      }

    });
    $sentence = 'Note does not apply when /third_party_settings/surface_resource_test/flag is up.';
    $sent = [
      'note' => 'Ring twice',
      'third_party_settings' => ['surface_resource_test' => ['flag' => 'up']],
    ];
    $this->assertSame(['note' => [$sentence, $sentence]], $this->refusals($builder->seal(), $sent));
  }

}
