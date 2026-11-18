<?php

declare(strict_types=1);

namespace Drupal\surface\Resource\Attribute;

/**
 * On a method: it tightens one input key against sibling values.
 *
 * Each parameter is a sibling input, in order, typed as that key's
 * value, and named for the key it is handed:
 *
 *   #[RefinesInput('bundle')]
 *   public static function bundleOf(string $entity_type): Schema
 *
 * A first parameter typed Schema, which is optional, is handed the key's
 * own schema as it stands, for a method that reads what the key allows:
 * its standard keywords through get(), its list of values through
 * \Drupal\surface\Refinement\ChoiceSet::of().
 *
 * Sealing refuses a parameter that names no declared input key, so a
 * renamed parameter is an error rather than a refiner that silently
 * never runs, and a method naming an output key. (`watches:`, a list
 * restating the parameter names, was removed on 2026-10-24 and is
 * refused at discovery.)
 *
 * The method runs once every watched sibling has a value, and again
 * when any of them changes. A method with no sibling parameters runs
 * once, when its resource or structure is built. It receives only what
 * it watches, so it is a pure function of its arguments and its result
 * can be reused for the same values. Sibling values arrive the way PHP
 * coerces a scalar argument outside strict mode, so a form's raw "1" reaches a
 * bool parameter as TRUE.
 *
 * It returns a Schema of what the watched values add to the key,
 * Schema::any() for nothing; the module applies it, and of its rule and
 * the key's the stricter stands, so an answer cannot widen. Or it answers
 * that the key does not apply to these values, the narrowest answer, with
 * DoesNotApply::here(), which its return type then names:
 *   public static function sendDayOnlyWhenWeekly(
 *     string $frequency,
 *   ): Schema|DoesNotApply
 * See docs/decisions.md#a-key-that-does-not-apply. Found on the
 * resource's or structure's own class and on any class carrying
 * #[Alters] for it.
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
 *       public function openRooms(Schema $room, string $venue): Schema
 *
 * The kind is enforced, not trusted. A static refiner is a static
 * method: the build refuses an instance method that does not declare
 * runtime, since an instance method can read the services its class was
 * built with (a static method may declare runtime too). With assertions
 * on, a static refiner runs with the global container guarded, so one
 * asking for a service is refused, and the served contract asks it again
 * for one combination per key it enumerates, so one answering
 * differently is refused. See docs/refinement.md#enforcing-the-kind and
 * docs/decisions.md#static-by-default-runtime-by-declaration.
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final class RefinesInput {

  /**
   * Constructs a RefinesInput attribute.
   *
   * @param string $key
   *   The input key this method tightens.
   * @param bool $runtime
   *   TRUE for a refiner whose answer no schema can state ahead of time:
   *   only the pipeline runs it, against the values it is handed.
   */
  public function __construct(
    public readonly string $key,
    public readonly bool $runtime = FALSE,
  ) {}

}
