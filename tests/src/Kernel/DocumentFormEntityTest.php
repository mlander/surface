<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\Core\Form\FormState;
use Drupal\field\Entity\FieldConfig;
use Drupal\surface\Form\Document\DocumentDrawer;
use Drupal\surface\Form\Document\DocumentNodes;
use Drupal\surface\Form\SurfaceFormBuilderInterface;
use Drupal\surface\SurfaceInterface;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the form drawn from a content entity's envelope.
 *
 * The page bundle's resource, with a reference to an image-like term and
 * a user reference whose field leaves Anonymous out: a field's own
 * description stands over its field item's, a field with none shows
 * none; the autocomplete asks the selection handler the field names with
 * the field's settings, which the document does not carry, so it offers
 * what the field accepts; a text input's size and a text area's rows from
 * the form display reach the document as hints alone; and the bundle
 * chooser's form carries the document's cacheability.
 *
 * Decision: see docs/decisions.md#the-form-is-drawn-from-the-document.
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class DocumentFormEntityTest extends SurfaceKernelTestBase {

  use ContentEntityFixtureTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = self::ENTITY_MODULES;

  /**
   * The featured image field's own description.
   */
  protected const FEATURED = 'Include an image. This appears as the image in search engine results.';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntityFixture();
    $this->addField('page', 'field_featured_image', 'entity_reference', ['target_type' => 'taxonomy_term'], ['handler_settings' => ['target_bundles' => ['tags' => 'tags']]], 'Featured image');
    FieldConfig::loadByName('node', 'page', 'field_featured_image')?->set('description', self::FEATURED)->save();
    $this->addField('page', 'field_related_user', 'entity_reference', ['target_type' => 'user'], [
      'handler' => 'default:user',
      'handler_settings' => [
        'include_anonymous' => FALSE,
        'filter' => ['type' => '_none'],
        'target_bundles' => NULL,
        'sort' => ['field' => '_none', 'direction' => 'ASC'],
        'auto_create' => FALSE,
      ],
    ], 'Related user');
    $display = EntityFormDisplay::load('node.page.default');
    $this->assertNotNull($display);
    $display->setComponent('field_featured_image', ['type' => 'entity_reference_autocomplete', 'weight' => 20])
      ->setComponent('field_related_user', ['type' => 'entity_reference_autocomplete', 'weight' => 21])
      ->save();
    User::create(['uid' => 0, 'name' => ''])->save();
    $this->setUpCurrentUser(admin: TRUE);
  }

  /**
   * Builds the page bundle's create operation.
   *
   * @return \Drupal\surface\SurfaceInterface
   *   The built resource.
   */
  protected function page(): SurfaceInterface {
    return $this->container->get('surface.resources')->buildOperation('entity.content:node.page', 'create');
  }

  /**
   * Finds the element drawn for the value at a JSON Pointer.
   *
   * @param array $element
   *   The container, or an element inside it.
   * @param string $pointer
   *   The pointer.
   *
   * @return array|null
   *   The element, or NULL where none is drawn.
   */
  protected static function elementAt(array $element, string $pointer): ?array {
    if (($element[SurfaceFormBuilderInterface::PATH_KEY] ?? NULL) === $pointer) {
      return $element;
    }
    foreach (DocumentDrawer::elementChildren($element) as $key) {
      $found = static::elementAt($element[$key], $pointer);
      if ($found !== NULL) {
        return $found;
      }
    }
    return NULL;
  }

  /**
   * A field's own description stands; a field item's never replaces it.
   */
  public function testTheFieldsOwnDescriptionStands(): void {
    $page = $this->page();
    $values = $page->getDefaultValues();
    $envelope = $this->formBuilder()->envelope($page, $values);
    $schema = $envelope->document['schema'];
    $this->assertSame(self::FEATURED, $schema['properties']['field_featured_image']['description'], 'The site states the field\'s description.');
    $this->assertNotEmpty(array_filter($schema['$defs'], static fn (array $def): bool => isset($def['description'])), 'Its field item\'s def states one of its own.');
    $nodes = DocumentNodes::of($envelope);
    $this->assertSame(self::FEATURED, $nodes->at('/field_featured_image')?->get('description'));
    $this->assertNull($nodes->at('/title')?->get('description'), 'A field with no description of its own shows none.');
    $this->assertNull($nodes->at('/field_related_user')?->get('description'));

    $form = $this->formBuilder()->buildSurfaceForm($page, $values, new FormState());
    $this->assertSame(self::FEATURED, (string) static::elementAt($form, '/field_featured_image')['#description']);
    $this->assertArrayNotHasKey('#description', static::elementAt($form, '/title'));
  }

  /**
   * The autocomplete asks the handler the field names, with its settings.
   */
  public function testTheAutocompleteOffersWhatTheFieldAccepts(): void {
    $page = $this->page();
    $values = $page->getDefaultValues();
    $schema = $this->formBuilder()->envelope($page, $values)->document['schema'];
    $check = $schema['$defs']['field.item:node.page.field_related_user']['properties']['target_id']['portable-check']['drupal:SurfaceValidReference'] ?? NULL;
    $this->assertSame(['entityTypeId' => 'user'], $check, 'The document names the entity type, not the handler.');

    $form = $this->formBuilder()->buildSurfaceForm($page, $values, new FormState());
    $element = static::elementAt($form, '/field_related_user/target_id');
    $this->assertSame('entity_autocomplete', $element['#type']);
    $this->assertSame('default:user', $element['#selection_handler']);
    $this->assertFalse($element['#selection_settings']['include_anonymous']);
    $selection = $this->container->get('plugin.manager.entity_reference_selection')->getInstance([
      'target_type' => 'user',
      'handler' => $element['#selection_handler'],
    ] + $element['#selection_settings']);
    $offered = array_map('intval', array_keys($selection->getReferenceableEntities()['user'] ?? []));
    $this->assertContains((int) $this->container->get('current_user')->id(), $offered);
    $this->assertNotContains(0, $offered, 'Anonymous is not offered.');

    $refused = [];
    foreach ($this->container->get('surface.pipeline')->validate($page, ['field_related_user' => ['target_id' => 0]] + $values) as $violation) {
      $refused[] = $violation->fullPath() . ' ' . $violation->code;
    }
    $this->assertContains('field_related_user.target_id check.drupal:SurfaceValidReference', $refused, 'Anonymous is refused, as the field refuses it.');
  }

  /**
   * A text input's size and a text area's rows reach the document as hints.
   */
  public function testSizeAndRowsAreHints(): void {
    $page = $this->page();
    $values = $page->getDefaultValues();
    $emitter = $this->container->get('surface.contract_emitter');
    $hinted = $emitter->emit($page, $values, 'entity.content:node.page', 'create', widgets: TRUE)->document['schema'];
    $plain = $emitter->emit($page, $values, 'entity.content:node.page', 'create')->document['schema'];
    $title = DocumentNodes::of($emitter->emit($page, $values, 'entity.content:node.page', 'create', widgets: TRUE))->at('/title/value');
    $this->assertSame(60, $title?->get('x-portable-ui')['options']['size'] ?? NULL, 'The title\'s text input, as the form display draws it.');
    $description = DocumentNodes::of($emitter->emit($page, $values, 'entity.content:node.page', 'create', widgets: TRUE))->at('/field_description/value');
    $this->assertSame(5, $description?->get('x-portable-ui')['options']['rows'] ?? NULL, 'The description\'s text area.');
    $this->assertStringContainsString('"size"', (string) json_encode($hinted));
    $this->assertStringNotContainsString('"size"', (string) json_encode($plain), 'Without the widget hints, no size.');
    $this->assertStringNotContainsString('"rows"', (string) json_encode($plain), 'Without the widget hints, no rows.');
  }

  /**
   * The bundle chooser's form carries the document's cacheability.
   *
   * Its document holds every bundle's variant, so the form carries each
   * variant's: a max-age of 0 and the tags of every list the document
   * reads. Pinned, so a change is seen.
   */
  public function testTheBundleChooserCarriesTheDocumentsCacheability(): void {
    $chooser = $this->container->get('surface.resources')->buildOperation('entity.content:node', 'create');
    $values = ['bundle' => 'page'] + $chooser->getDefaultValues();
    $envelope = $this->formBuilder()->envelope($chooser, $values);
    $form = $this->formBuilder()->buildSurfaceForm($chooser, $values, new FormState());
    $this->assertSame(0, $form['#cache']['max-age']);
    $this->assertSame($envelope->cacheability->getCacheMaxAge(), $form['#cache']['max-age']);
    $tags = $form['#cache']['tags'];
    $this->assertEqualsCanonicalizing($envelope->cacheability->getCacheTags(), $tags, 'The document\'s tags, every one.');
    foreach (['config:surface.settings', 'entity_bundles', 'entity_field_info', 'taxonomy_term_list', 'user_list'] as $tag) {
      $this->assertContains($tag, $tags, 'The tags of every list the document reads.');
    }
    $this->assertCount(77, $tags, 'Every bundle variant\'s fields, displays and overrides: pinned, so a change is seen.');
  }

}
