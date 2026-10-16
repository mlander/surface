<?php

declare(strict_types=1);

namespace Drupal\data_surface\Surface\Attribute;

/**
 * On a method: it tightens one input key against sibling values.
 *
 * The first parameter is the key's definition (a clone). Every
 * parameter after it is a sibling input, in order, typed as that key's
 * value. Which siblings is said once, in one of two ways:
 *
 *   - by parameter name, when `watches` is omitted:
 *       #[RefinesInput('bundle')]
 *       public function bundleOf(
 *         DataDefinitionInterface $bundle, string $entity_type)
 *
 *   - by `watches`, which then must match the parameters in order; a
 *     renamed parameter is a seal-time error rather than a refiner
 *     that silently never runs:
 *       #[RefinesInput('field', watches: ['entity_type', 'bundle'])]
 *       public function fieldOf(DataDefinitionInterface $field,
 *         string $entity_type, string $bundle)
 *
 * Either way, sealing refuses a sibling that is not a declared input
 * key, and a method naming an output key.
 *
 * The method runs once every watched sibling has a value, and again
 * when any of them changes. A method with no sibling parameters runs
 * once, when the surface is built. It receives only what it watches, so
 * it is a pure function of its arguments and its result can be reused
 * for the same values. Sibling values arrive the way PHP coerces a
 * scalar argument outside strict mode, so a form's raw "1" reaches a
 * bool parameter as TRUE.
 *
 * It returns the definition, tightened with plain core API; the
 * framework checks the result is narrower. Found on the surface's own
 * class and on any class carrying #[AltersSurface] for it.
 *
 * A refiner is one of two kinds, and says which:
 *
 *   - static, the default: a pure function of the siblings it watches,
 *     which the served contract may run ahead of time for every value
 *     those siblings could take and write down as JSON Schema
 *     conditionals;
 *   - runtime, `runtime: TRUE`: its answer depends on something no
 *     schema can state (a lookup in storage or a remote service,
 *     uniqueness, the current account, the time), so nothing runs it
 *     ahead of time and the full schema keeps the key at its declared
 *     width, marked `runtime`. It still names the siblings it watches,
 *     so a form refines again when one changes; one that watches nothing
 *     runs on every refinement, validation and submission:
 *       #[RefinesInput('room', runtime: TRUE)]
 *       public function openRooms(
 *         DataDefinitionInterface $room, string $venue)
 *
 * The kind is a declaration, not something the module can check: a
 * static refiner that reads site state anyway is a schema that lies.
 * See docs/refinement.md and
 * docs/decisions.md#static-by-default-runtime-by-declaration.
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final class RefinesInput {

  /**
   * Constructs a RefinesInput attribute.
   *
   * @param string $key
   *   The input key this method tightens.
   * @param string[]|null $watches
   *   The sibling keys, in parameter order. NULL means read them from
   *   the parameter names.
   * @param bool $runtime
   *   TRUE for a refiner whose answer no schema can state ahead of time:
   *   only the pipeline runs it, against the values it is handed.
   */
  public function __construct(
    public readonly string $key,
    public readonly ?array $watches = NULL,
    public readonly bool $runtime = FALSE,
  ) {}

}
