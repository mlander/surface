<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\MapDataDefinition;
use Drupal\data_surface\DataSurface;
use Drupal\data_surface\DefinitionMap;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\DefinitionMetadata;
use Drupal\data_surface\Pipeline\UnknownKeysException;
use Drupal\data_surface\Target\PluginConfigurationTarget;
use Drupal\data_surface\Target\StateTarget;
use Drupal\data_surface_test\ConfigurableHostPlugin;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests that every caller reaches storage through the same pipeline.
 *
 * The claim the pipeline exists to make good on: a generated form and a
 * raw payload carrying the same strings produce the same values, because
 * both are coerced by accept() and nothing else. Around that, the stages
 * a non-form caller depends on — refusing undeclared keys, merging over
 * stored values, preparing without writing, and leaving host-owned
 * storage keys alone.
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class PipelineParityTest extends DataSurfaceKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'data_surface', 'data_surface_test'];

  /**
   * The state key the state target writes to.
   */
  protected const STATE_KEY = 'data_surface.parity';

  /**
   * Builds a surface covering every shape the coercion rules touch.
   *
   * A string, an integer, a float, a boolean, a select with labeled
   * choices, a nested map carrying a default, and a locked key.
   */
  protected function surface(): DataSurfaceInterface {
    $badge = DataDefinition::create('string')
      ->setLabel('Badge')
      ->setRequired(FALSE)
      ->addConstraint('Choice', ['choices' => ['star', 'flame']]);
    DefinitionMetadata::setDefaultValue($badge, 'flame');
    $extras = MapDataDefinition::create()->setLabel('Extras')->setRequired(FALSE);
    $extras->setPropertyDefinition('badge', $badge);
    $extras->setPropertyDefinition('weight', DataDefinition::create('integer')
      ->setLabel('Weight')
      ->setRequired(FALSE));

    $definitions = [
      'title' => DataDefinition::create('string')->setLabel('Title')->setRequired(TRUE),
      'count' => DataDefinition::create('integer')->setLabel('Count')->setRequired(FALSE),
      'ratio' => DataDefinition::create('float')->setLabel('Ratio')->setRequired(FALSE),
      'active' => DataDefinition::create('boolean')->setLabel('Active')->setRequired(FALSE),
      'mode' => DataDefinition::create('integer')
        ->setLabel('Mode')
        ->setRequired(FALSE)
        ->addConstraint('LabeledChoice', [
          'choices' => [0, 1, 2],
          'labels' => [0 => 'Disabled', 1 => 'Optional', 2 => 'Required'],
        ]),
      'extras' => $extras,
      'flavor' => DataDefinition::create('string')->setLabel('Flavor')->setRequired(FALSE),
    ];
    DefinitionMetadata::setDefaultValue($definitions['title'], 'Hello');
    DefinitionMetadata::setDefaultValue($definitions['mode'], 1);
    DefinitionMetadata::setDefaultValue($definitions['flavor'], 'vanilla');

    return new DataSurface(
      DefinitionMap::fromArrays(
        definitions: $definitions,
        locked: ['flavor'],
      ),
    );
  }

  /**
   * The raw payload a non-form caller would send.
   *
   * Everything arrives as a string, which is what a query string, a
   * config action, or an agent that read the surface would produce.
   *
   * @return array
   *   The payload.
   */
  protected function payload(): array {
    return [
      'title' => 'Typed title',
      'count' => '7',
      'ratio' => '1.5',
      'active' => '1',
      'mode' => '2',
      'extras' => ['badge' => 'star', 'weight' => '3'],
      // Tampering with a locked key is ignored on both paths.
      'flavor' => 'chocolate',
    ];
  }

  /**
   * Tests the form path and the payload path produce identical values.
   */
  public function testFormAndPayloadAgree(): void {
    $surface = $this->surface();

    // The form path: build the elements, hand Form API what a browser
    // submits (strings, and an integer for the checkbox), extract.
    $form = $this->formBuilder()->buildSurfaceForm($surface, $surface->getDefaultValues(), new FormState());
    $form_state = new FormState();
    $form_state->setValues([
      'title' => 'Typed title',
      'count' => '7',
      'ratio' => '1.5',
      'active' => 1,
      'mode' => '2',
      'extras' => ['badge' => 'star', 'weight' => '3'],
      'flavor' => 'chocolate',
    ]);
    $from_form = $this->formBuilder()->extractSurfaceValues($surface, $form, $form_state);

    // The payload path: the equivalent raw strings, straight to accept().
    $from_payload = $this->pipeline()->accept($surface, $this->payload());

    $this->assertSame($from_payload, $from_form);
    $this->assertSame([
      'title' => 'Typed title',
      'count' => 7,
      'ratio' => 1.5,
      'active' => TRUE,
      'mode' => 2,
      'extras' => ['badge' => 'star', 'weight' => 3],
      'flavor' => 'vanilla',
    ], $from_form);

    // And what both paths produced validates.
    $this->assertCount(0, $this->pipeline()->validate($surface, $from_form));
  }

  /**
   * Tests empty submissions mean the same thing on both paths.
   */
  public function testEmptyValuesAgree(): void {
    $surface = $this->surface();
    $empty = [
      'title' => '',
      'count' => '',
      'ratio' => '',
      'active' => '',
      'mode' => '',
      'extras' => ['badge' => '', 'weight' => ''],
      'flavor' => '',
    ];

    $form = $this->formBuilder()->buildSurfaceForm($surface, $surface->getDefaultValues(), new FormState());
    $form_state = new FormState();
    $form_state->setValues($empty);
    $from_form = $this->formBuilder()->extractSurfaceValues($surface, $form, $form_state);
    $from_payload = $this->pipeline()->accept($surface, $empty);

    $this->assertSame($from_payload, $from_form);
    // An empty submission is "not configured", and that means the same
    // thing for every data type: the key holds nothing. There is no
    // required-string exception and no checkbox exception, which is what
    // used to make one empty form produce three different answers.
    $this->assertNull($from_form['title']);
    $this->assertNull($from_form['count']);
    $this->assertNull($from_form['ratio']);
    $this->assertNull($from_form['active']);
    $this->assertNull($from_form['mode']);
    $this->assertSame(['badge' => NULL, 'weight' => NULL], $from_form['extras']);

    $violations = $this->pipeline()->validate($surface, $from_form);
    $this->assertSame(['title'], $violations->keys());
    $this->assertSame('', $violations->byKey('title')[0]->path);
    // The surface's own message, translatable, rather than whichever
    // message a type-specific constraint would have produced.
    $this->assertSame('Title is required.', (string) $violations->byKey('title')[0]->message);
  }

  /**
   * Tests an undeclared key is refused rather than dropped.
   */
  public function testUnknownKeysAreRefused(): void {
    $surface = $this->surface();
    $target = new StateTarget($this->container->get('state'), self::STATE_KEY);

    $result = $this->pipeline()->submit($surface, ['title' => 'Fine', 'bogus' => 'x'], $target);
    $this->assertFalse($result->isValid());
    $this->assertSame(['bogus'], $result->violations->keys());
    $this->assertSame('Unknown key bogus.', (string) $result->violations->byKey('bogus')[0]->message);
    $this->assertSame('', $result->violations->byKey('bogus')[0]->path);
    $this->assertNull($result->prepared);
    $this->assertFalse($result->committed);
    $this->assertNull($this->container->get('state')->get(self::STATE_KEY));

    // A nested undeclared key is reported on its surface key, with the
    // rest of its path, exactly like a constraint violation inside a map.
    $nested = $this->pipeline()->submit($surface, ['extras' => ['badge' => 'star', 'bogus' => 1]], $target);
    $this->assertSame(['extras'], $nested->violations->keys());
    $this->assertSame('Unknown key extras.bogus.', (string) $nested->violations->byKey('extras')[0]->message);
    $this->assertSame('bogus', $nested->violations->byKey('extras')[0]->path);

    // The exception itself names the keys and the level they were found
    // in, for callers that would rather catch than read violations.
    try {
      $this->pipeline()->accept($surface, ['extras' => ['bogus' => 1]]);
      $this->fail('Expected an UnknownKeysException.');
    }
    catch (UnknownKeysException $e) {
      $this->assertSame(['bogus'], $e->getKeys());
      $this->assertSame('extras', $e->getPath());
    }
  }

  /**
   * Tests partial input merges over the values the target holds.
   */
  public function testPartialInputMergesOverCurrent(): void {
    $surface = $this->surface();
    $state = $this->container->get('state');
    $state->set(self::STATE_KEY, [
      'title' => 'Stored title',
      'count' => 3,
      'ratio' => 0.5,
      'active' => TRUE,
      'mode' => 2,
      'extras' => ['badge' => 'star', 'weight' => 2],
      'flavor' => 'vanilla',
    ]);
    $target = new StateTarget($state, self::STATE_KEY);

    $result = $this->pipeline()->submit($surface, ['count' => '9', 'extras' => ['weight' => '4']], $target);

    $this->assertTrue($result->isValid());
    $this->assertTrue($result->committed);
    // Only what was sent moved; the stored siblings survived, at both
    // levels, and the untouched default did not overwrite storage.
    $this->assertSame([
      'title' => 'Stored title',
      'count' => 9,
      'ratio' => 0.5,
      'active' => TRUE,
      'mode' => 2,
      'extras' => ['badge' => 'star', 'weight' => 4],
      'flavor' => 'vanilla',
    ], $result->values);
    $this->assertSame($result->values, $state->get(self::STATE_KEY));
  }

  /**
   * Tests a dry run prepares the artifact and writes nothing.
   */
  public function testDryRunPreparesWithoutCommitting(): void {
    $surface = $this->surface();
    $state = $this->container->get('state');
    $target = new StateTarget($state, self::STATE_KEY);

    $result = $this->pipeline()->submit($surface, $this->payload(), $target, TRUE);

    $this->assertTrue($result->isValid());
    $this->assertFalse($result->committed);
    $this->assertNotNull($result->prepared);
    $this->assertSame($result->values, $result->prepared->artifact);
    $this->assertSame([], $result->prepared->dependencies);
    $this->assertNull($state->get(self::STATE_KEY));

    // The same submission without the dry run writes exactly what the
    // dry run previewed.
    $committed = $this->pipeline()->submit($surface, $this->payload(), $target);
    $this->assertTrue($committed->committed);
    $this->assertSame($result->prepared->artifact, $state->get(self::STATE_KEY));
  }

  /**
   * Tests a plugin's host-owned configuration keys survive a submit.
   */
  public function testPluginConfigurationTargetKeepsHostKeys(): void {
    $surface = $this->surface();
    $plugin = new ConfigurableHostPlugin();
    $target = new PluginConfigurationTarget($plugin);

    // Loading narrows to the surface's keys, so the host's own keys
    // never reach accept() and cannot be refused as unknown.
    $this->assertSame(['title' => 'Stored title', 'count' => 3], $target->load($surface));

    $result = $this->pipeline()->submit($surface, $this->payload(), $target);

    $this->assertTrue($result->isValid());
    $this->assertTrue($result->committed);
    $this->assertSame([
      'title' => 'Typed title',
      'count' => 7,
      'ratio' => 1.5,
      'active' => TRUE,
      'mode' => 2,
      'extras' => ['badge' => 'star', 'weight' => 3],
      'flavor' => 'vanilla',
      'id' => 'demo_block',
      'label' => 'Demo',
      'provider' => 'data_surface',
    ], $plugin->getConfiguration());
  }

}
