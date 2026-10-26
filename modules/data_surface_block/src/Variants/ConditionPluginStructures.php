<?php

declare(strict_types=1);

namespace Drupal\data_surface_block\Variants;

use Drupal\Core\Condition\ConditionManager;
use Drupal\Core\Condition\ConditionPluginBase;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Plugin\Context\ContextDefinitionInterface;
use Drupal\Core\Plugin\Context\ContextHandlerInterface;
use Drupal\Core\Plugin\Context\ContextRepositoryInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\data_surface\Contract\ConfigSchemaDocument;
use Drupal\data_surface\Resource\Attribute\UsesStructure;
use Drupal\data_surface\Resource\SiteList;
use Drupal\data_surface\Resource\SiteListInterface;

/**
 * One variant per condition a placed block's visibility may hold.
 *
 * The conditions the block layout offers, filtered as it filters them:
 * those whose contexts the site can give, less the current theme (a
 * block is placed in one theme already) and the language while the site
 * has one. Each is offered under its label, in the order of their ids.
 *
 * Each one's settings are its declared structure, when its class names
 * one, or what `condition.plugin.<id>` says, less the keys the condition
 * entry holds itself (its id and whether it is negated), with its
 * defaults; and, for a condition that reads a context, a `context_mapping`
 * of one key per context it reads, each offering the contexts the site
 * has that fit it, the first of them its default, as the block layout
 * assigns it.
 *
 * Decision: see docs/decisions.md#a-placed-block-is-a-resource.
 */
final class ConditionPluginStructures implements SiteListInterface {

  use StringTranslationTrait;

  /**
   * The keys of a condition's configuration its entry holds itself.
   */
  public const BASE = ['id', 'negate', 'context_mapping', 'uuid'];

  /**
   * The conditions never offered: a block's theme is its placement's.
   */
  protected const NEVER = ['current_theme'];

  /**
   * The list, once read in this request.
   */
  protected ?SiteList $list = NULL;

  /**
   * Constructs the provider.
   *
   * @param \Drupal\Core\Condition\ConditionManager $conditions
   *   The condition plugin manager.
   * @param \Drupal\Core\Plugin\Context\ContextRepositoryInterface $contextRepository
   *   The contexts the site can give.
   * @param \Drupal\Core\Plugin\Context\ContextHandlerInterface $contextHandler
   *   Matches a condition's contexts with the site's.
   * @param \Drupal\Core\Language\LanguageManagerInterface $languageManager
   *   Says whether the site has more than one language.
   * @param \Drupal\data_surface\Contract\ConfigSchemaDocument $schemas
   *   Writes a condition's schema as JSON Schema.
   * @param \Drupal\Core\StringTranslation\TranslationInterface $string_translation
   *   The string translation service.
   */
  public function __construct(
    protected readonly ConditionManager $conditions,
    protected readonly ContextRepositoryInterface $contextRepository,
    protected readonly ContextHandlerInterface $contextHandler,
    protected readonly LanguageManagerInterface $languageManager,
    protected readonly ConfigSchemaDocument $schemas,
    TranslationInterface $string_translation,
  ) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * {@inheritdoc}
   */
  public function list(): SiteList {
    if ($this->list !== NULL) {
      return $this->list;
    }
    $list = (new SiteList())->addCacheTags(['config:core.extension', 'config:configurable_language_list']);
    $list->addCacheableDependency($this->conditions);
    $available = $this->contextRepository->getAvailableContexts();
    $definitions = $this->conditions->getFilteredDefinitions('block_ui', $available);
    ksort($definitions);
    foreach ($definitions as $id => $definition) {
      $id = (string) $id;
      if (in_array($id, self::NEVER, TRUE) || ($id === 'language' && !$this->languageManager->isMultilingual())) {
        continue;
      }
      $label = $definition['label'] ?? $id;
      $structure = $definition[UsesStructure::DEFINITION_KEY] ?? NULL;
      if (is_string($structure) && $structure !== '') {
        $list->add($id, $label, structure: $structure);
        continue;
      }
      $schema = $this->schemas->object('condition.plugin.' . $id, self::BASE, $this->defaults($id));
      $mapping = $this->contextMapping($definition['context_definitions'] ?? [], $available);
      if ($mapping !== NULL) {
        $schema['properties'] = (array) $schema['properties'] + ['context_mapping' => $mapping];
      }
      $list->add($id, $label, schema: $schema);
    }
    return $this->list = $list;
  }

  /**
   * Writes the context mapping of a condition that reads contexts.
   *
   * @param array $contexts
   *   The condition's context definitions, by name.
   * @param \Drupal\Core\Plugin\Context\ContextInterface[] $available
   *   The contexts the site can give, by their ids.
   *
   * @return array|null
   *   The mapping's JSON Schema, one key per context the condition reads;
   *   NULL for a condition that reads none.
   */
  protected function contextMapping(array $contexts, array $available): ?array {
    $properties = [];
    foreach ($contexts as $name => $context) {
      if (!$context instanceof ContextDefinitionInterface) {
        continue;
      }
      $matching = array_keys($this->contextHandler->getMatchingContexts($available, $context));
      $property = [
        'title' => (string) ($context->getLabel() ?: $name),
        'description' => (string) $this->t('The context the condition reads its @name from.', ['@name' => $name]),
        'type' => 'string',
      ];
      if ($matching !== []) {
        $property['enum'] = array_map('strval', $matching);
        $property['default'] = (string) $matching[0];
      }
      $properties[(string) $name] = $property;
    }
    if ($properties === []) {
      return NULL;
    }
    return [
      'title' => (string) $this->t('Context assignments'),
      'type' => 'object',
      'properties' => $properties,
      'additionalProperties' => FALSE,
    ];
  }

  /**
   * Reads a condition's own defaults, less the keys its entry holds.
   *
   * @param string $id
   *   The plugin id.
   *
   * @return array
   *   The defaults, by key; none when the condition cannot be built alone.
   */
  protected function defaults(string $id): array {
    try {
      $condition = $this->conditions->createInstance($id);
    }
    catch (\Throwable) {
      return [];
    }
    return $condition instanceof ConditionPluginBase ? array_diff_key($condition->defaultConfiguration(), array_flip(self::BASE)) : [];
  }

}
