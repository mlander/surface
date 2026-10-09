// The served contract, as data_surface_react's ContractEmitter writes it:
// JSON Schema 2020-12 with an `x-surface` keyword on every property.

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
    const decided = condition.if.properties[by];
    if (decided !== undefined && sameValue(decided.const, value) && condition.then.properties[name]) {
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
