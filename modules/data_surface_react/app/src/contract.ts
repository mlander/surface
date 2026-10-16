// The served contract, as the main module's ContractEmitter writes it:
// JSON Schema 2020-12 with an `x-surface` keyword on every property, each
// subsurface defined once under `$defs` and referred to where it is used.
// The app reads it through inlineSchema(), once per contract, so nothing
// else here follows a reference.

export type Widget =
  | 'select'
  | 'radios'
  | 'checkbox'
  | 'number'
  | 'text'
  | 'textarea'
  | 'email'
  | 'fieldset'
  | 'slot'
  | 'list';

export interface EmptyOption {
  show: boolean;
  label: string;
}

export interface Extension {
  widget: Widget | null;
  locked: boolean;
  dependsOn: string[];
  refined: boolean;
  stale: boolean;
  emptyOption?: EmptyOption;
  multiple?: boolean;
  by?: string;
  variants?: string[];
  chosen?: string | null;
  variant?: string;
  checkedOnServer?: string[];
  /** A Regex's own message: how a person is told what `pattern` allows. */
  patternMessage?: string;
  /** On an object that only groups each module's fieldset: draw nothing around them. */
  group?: boolean;
  /** On a module's fieldset inside a group: draw it right after this sibling of the group instead. */
  after?: string;
  /**
   * A runtime refiner narrows the key too: a rule only the server applies,
   * when asked, which no schema states ahead of time. The full schema
   * keeps the key wider; this document is refined by it for its values.
   */
  runtime?: boolean;
}

export interface Choice {
  const: unknown;
  title?: string;
  description?: string;
}

export interface Conditional {
  if: { properties: Record<string, { const: unknown }>; required?: string[] };
  then: { properties: Record<string, Schema> };
}

export interface Schema {
  $schema?: string;
  /** A reference to a def, `#/$defs/<id>`; resolved by inlineSchema(). */
  $ref?: string;
  /** The root's subschemas, one per child surface, by id. */
  $defs?: Record<string, Schema>;
  title?: string;
  description?: string;
  type?: string | string[];
  format?: string;
  properties?: Record<string, Schema>;
  required?: string[];
  additionalProperties?: boolean;
  allOf?: Conditional[];
  items?: Schema;
  oneOf?: Choice[];
  minimum?: number;
  maximum?: number;
  minLength?: number;
  maxLength?: number;
  minItems?: number;
  maxItems?: number;
  pattern?: string;
  default?: unknown;
  examples?: unknown[];
  readOnly?: boolean;
  writeOnly?: boolean;
  const?: unknown;
  'x-surface'?: Extension;
}

export type Values = Record<string, unknown>;

export interface Contract {
  surface: string;
  situation: string | null;
  label: string | null;
  schema: Schema;
  values: Values;
  stale: string[];
  outputs?: Schema;
  /** The stored values' fingerprint, on a GET and a submit's contract. */
  fingerprint?: string | null;
  discarded?: string[];
}

export interface Violation {
  path: string;
  message: string;
}

export interface Validation {
  valid: boolean;
  violations: Violation[];
  stale: Violation[];
  values: Values;
  prepared: unknown;
}

/** Where a created thing now lives: a situation and its parameters. */
export interface Created {
  surface: string;
  situation: string;
  parameters: Record<string, string | number>;
}

/** What /submit answers: refused, or written with the fresh contract. */
export interface Submission {
  committed: boolean;
  valid: boolean;
  violations: Violation[];
  stale: Violation[];
  outputs: Values;
  contract: Contract | null;
  created: Created | null;
}

/** The JSON type a schema is, null aside. */
export function jsonType(schema: Schema): string | undefined {
  const types = Array.isArray(schema.type) ? schema.type : schema.type === undefined ? [] : [schema.type];
  return types.find((type) => type !== 'null');
}

/** The values a select offers: every `oneOf` entry but the untitled null. */
export function choices(schema: Schema): Choice[] {
  return (schema.oneOf ?? []).filter((choice) => !(choice.const === null && choice.title === undefined));
}

/** Whether two values are the same option, as a select compares them. */
export function sameValue(a: unknown, b: unknown): boolean {
  if (a === null || a === undefined || b === null || b === undefined) {
    return false;
  }
  return String(a) === String(b);
}

/** Whether a value holds nothing, by the module's own rule: null or ''. */
export function isEmpty(value: unknown): boolean {
  return value === null || value === undefined || value === '';
}

/** Where every subschema a contract defines is: a JSON pointer prefix. */
export const DEFS = '#/$defs/';

type Json = Record<string, unknown>;

function sameJson(a: unknown, b: unknown): boolean {
  return JSON.stringify(a) === JSON.stringify(b);
}

/** The key under `$defs` a local reference points at, or null for another reference. */
export function defName(reference: string): string | null {
  if (!reference.startsWith(DEFS)) {
    return null;
  }
  const segment = decodeURIComponent(reference.slice(DEFS.length));
  return segment.includes('/') ? null : segment.replace(/~1/g, '/').replace(/~0/g, '~');
}

/**
 * Lays one schema over another: the later schema's value for every keyword
 * it states, which is right because a narrowing only narrows, except that
 * `properties` merge one by one, `required` is the union and `allOf` the
 * concatenation. A keyword already there keeps its place.
 */
function merge(base: Json, over: Json): Json {
  const merged: Json = { ...base };
  for (const [keyword, value] of Object.entries(over)) {
    const before = merged[keyword];
    if (keyword === 'properties' && isObject(before) && isObject(value)) {
      const properties: Json = { ...before };
      for (const [name, property] of Object.entries(value)) {
        const was = properties[name];
        properties[name] = isObject(was) && isObject(property) ? merge(was, property) : property;
      }
      merged[keyword] = properties;
    }
    else if (keyword === 'required' && Array.isArray(before) && Array.isArray(value)) {
      merged[keyword] = [...new Set([...before, ...value])];
    }
    else if (keyword === 'allOf' && Array.isArray(before) && Array.isArray(value)) {
      merged[keyword] = [...before, ...value.filter((entry) => !before.some((had) => sameJson(had, entry)))];
    }
    else {
      merged[keyword] = value;
    }
  }
  return merged;
}

/**
 * Resolves one schema's references, and says which defs it followed.
 *
 * A reference is replaced by its def where it stood; an `allOf` holding a
 * reference is a composition, each entry that is not a conditional laid
 * over in order and the conditionals kept. A def reached again on the way
 * down (`ancestors`) is a recursion and its reference is kept; one reached
 * again beside itself is already laid over.
 */
function resolveAt(schema: Json, root: Json, ancestors: string[]): [Json, string[]] {
  const followed: string[] = [];
  let resolved: Json = {};
  const follow = (name: string, reference: string): void => {
    const def = isObject(root.$defs) ? root.$defs[name] : undefined;
    if (ancestors.includes(name) || !isObject(def)) {
      resolved = merge(resolved, { $ref: reference });
      return;
    }
    if (followed.includes(name)) {
      return;
    }
    followed.push(name);
    resolved = merge(resolved, resolveAt(def, root, [...ancestors, name])[0]);
  };
  for (const [keyword, value] of Object.entries(schema)) {
    const name = keyword === '$ref' && typeof value === 'string' ? defName(value) : null;
    if (name !== null) {
      follow(name, value as string);
      continue;
    }
    if (keyword === 'allOf' && Array.isArray(value) && value.some((entry) => isObject(entry) && '$ref' in entry)) {
      const conditions: unknown[] = [];
      for (const entry of value) {
        if (!isObject(entry) || 'if' in entry) {
          conditions.push(entry);
          continue;
        }
        const referred = typeof entry.$ref === 'string' ? defName(entry.$ref) : null;
        if (referred !== null && Object.keys(entry).length === 1) {
          follow(referred, entry.$ref as string);
          continue;
        }
        resolved = merge(resolved, resolveAt(entry, root, ancestors)[0]);
      }
      if (conditions.length > 0) {
        resolved = merge(resolved, { allOf: conditions });
      }
      continue;
    }
    resolved = merge(resolved, { [keyword]: value });
  }
  return [resolved, [...ancestors, ...followed]];
}

/**
 * A property's effective schema, its own references resolved.
 *
 * Follows a local `$ref` to its def and merges the keywords beside it; an
 * `allOf` of a reference and a narrowing is applied by taking the
 * narrowing's value for every keyword it states. The schemas inside are
 * left as written; inlineSchema() resolves those too.
 */
export function resolveSchema(schema: Schema, root: Schema): Schema {
  return resolveAt(schema as Json, root as Json, [])[0] as Schema;
}

/**
 * A schema with every reference resolved, at every depth.
 *
 * What the app draws from: run once per contract, on load and on each
 * refine, so a property, a slot's conditional and a fieldset's keys are
 * each read inline. At the root, `$defs` is dropped. PHP's twin is
 * `Drupal\data_surface\Contract\SchemaResolver::inline()`.
 */
export function inlineSchema(schema: Schema, root?: Schema, ancestors: string[] = []): Schema {
  const base = (root ?? schema) as Json;
  const [resolved, followed] = resolveAt(schema as Json, base, ancestors);
  if (root === undefined) {
    delete resolved.$defs;
  }
  const inline = (inner: unknown): unknown => (isObject(inner) ? inlineSchema(inner as Schema, base as Schema, followed) : inner);
  if (isObject(resolved.properties)) {
    resolved.properties = Object.fromEntries(Object.entries(resolved.properties).map(([name, property]) => [name, inline(property)]));
  }
  if (isObject(resolved.items)) {
    resolved.items = inline(resolved.items);
  }
  if (Array.isArray(resolved.allOf)) {
    resolved.allOf = resolved.allOf.map((condition: unknown) => {
      if (!isObject(condition)) {
        return condition;
      }
      const branches: Json = { ...condition };
      for (const branch of ['then', 'else']) {
        if (isObject(branches[branch])) {
          branches[branch] = inline(branches[branch]);
        }
      }
      return branches;
    });
  }
  return resolved as Schema;
}

/**
 * The shape a slot has for the values of the object it sits in.
 *
 * Read from the parent's `allOf`: the `then` whose `if` names the value
 * the deciding key holds. Null when the deciding key chose nothing.
 */
export function resolveSlot(parent: Schema, name: string, parentValues: Values): Schema | null {
  const by = parent.properties?.[name]?.['x-surface']?.by;
  if (by === undefined) {
    return null;
  }
  const value = parentValues[by];
  for (const condition of parent.allOf ?? []) {
    const decided = condition.if?.properties[by];
    if (decided !== undefined && sameValue(decided.const, value) && condition.then?.properties[name]) {
      return condition.then.properties[name];
    }
  }
  return null;
}

/**
 * Every dotted path something refines against, as the values stand.
 *
 * `dependsOn` names siblings, so each is resolved against the path of
 * the object it sits in; fieldsets and resolved slots are walked.
 */
export function watchedPaths(schema: Schema, values: Values, prefix = ''): Set<string> {
  const watched = new Set<string>();
  for (const [name, property] of Object.entries(schema.properties ?? {})) {
    for (const dependency of property['x-surface']?.dependsOn ?? []) {
      watched.add(prefix + dependency);
    }
    const nested = values[name];
    const nestedValues = isObject(nested) ? nested : {};
    const widget = property['x-surface']?.widget;
    const inner = widget === 'slot' ? resolveSlot(schema, name, values) : widget === 'fieldset' ? property : null;
    if (inner !== null) {
      watchedPaths(inner, nestedValues, `${prefix}${name}.`).forEach((path) => watched.add(path));
    }
  }
  return watched;
}

export function isObject(value: unknown): value is Values {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}

/** Reads the value at a dotted path. */
export function getAt(values: unknown, path: string): unknown {
  return path.split('.').reduce<unknown>((current, segment) => {
    if (Array.isArray(current)) {
      return current[Number(segment)];
    }
    return isObject(current) ? current[segment] : undefined;
  }, values);
}

/** Writes a value at a dotted path, copying what it passes through. */
export function setAt<T>(values: T, path: string, value: unknown): T {
  const [head, ...rest] = path.split('.');
  const nested = rest.length === 0 ? value : setAt(getAt(values, head) ?? {}, rest.join('.'), value);
  if (Array.isArray(values)) {
    const copy = [...values];
    copy[Number(head)] = nested;
    return copy as T;
  }
  return { ...(isObject(values) ? values : {}), [head]: nested } as T;
}

/** Turns a path into an element id. */
export function fieldId(path: string): string {
  return `dsr-${path.replace(/[^A-Za-z0-9_-]/g, '-')}`;
}
