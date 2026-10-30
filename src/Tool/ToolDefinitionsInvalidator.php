<?php

declare(strict_types=1);

namespace Drupal\surface\Tool;

use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Clears the derived tools when what they were derived from changes.
 *
 * The Tool API keeps its plugin definitions in the discovery cache, with
 * no cache tags, until the next cache rebuild. A derived tool's `values`
 * are read from the site wherever a resource's keys are (a provider's
 * list of the site's things, a derived resource's bundle and fields), so
 * the deriver remembers the cache tags of everything it built
 * (remember()), and an invalidation of any of them clears the tool
 * definitions, which the next listing derives again.
 *
 * It asks the container for State and the tool manager only when one of
 * the remembered tags is invalidated: most invalidations are no reason to
 * read either.
 *
 * Decision: see docs/decisions.md#keys-that-come-from-the-site.
 *
 * @internal
 */
final class ToolDefinitionsInvalidator implements CacheTagsInvalidatorInterface {

  /**
   * The State key the remembered tags are kept under.
   */
  public const STATE = 'surface.tool_listed_cache_tags';

  /**
   * The remembered tags, by tag, once read in this request.
   *
   * @var array<string, true>|null
   */
  protected ?array $tags = NULL;

  /**
   * Constructs a ToolDefinitionsInvalidator.
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The container, asked for State and the tool manager when needed.
   */
  public function __construct(
    protected readonly ContainerInterface $container,
  ) {}

  /**
   * Remembers what the derived tool definitions were read from.
   *
   * @param string[] $tags
   *   The cache tags of everything the deriver built.
   */
  public function remember(array $tags): void {
    $tags = array_values(array_unique($tags));
    sort($tags);
    $state = $this->container->get('state');
    if ($state->get(self::STATE) !== $tags) {
      $state->set(self::STATE, $tags);
    }
    $this->tags = array_fill_keys($tags, TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function invalidateTags(array $tags): void {
    $this->tags ??= array_fill_keys($this->container->get('state')->get(self::STATE, []), TRUE);
    if (array_intersect_key(array_flip($tags), $this->tags) === []) {
      return;
    }
    $this->container->get('plugin.manager.tool')->clearCachedDefinitions();
  }

}
