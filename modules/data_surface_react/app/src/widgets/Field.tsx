import type { Arrangement, ArrangementGroup, Schema, Values } from '../contract';
import { fieldId, isObject, jsonType, resolveVariants } from '../contract';
import {
  AutocompleteField,
  CheckboxesField,
  CheckboxField,
  FixedField,
  MultipleSelectField,
  NumberField,
  RadiosField,
  SelectField,
  TextareaField,
  TextField,
  UnsupportedField,
} from './Inputs';
import { Description, Errors, isLocked, RequiredMarker, type FieldProps } from './Shell';

/**
 * One component per `x-surface.widget`, as the Form API maps each
 * definition to an element: options to a select or radios, a list of
 * them to checkboxes or a multiple select, a map to a fieldset, a string
 * to a text input, a time input or a textarea, a number to a number
 * input, a boolean to a checkbox, a list of free values to rows; and the
 * one the Form API has no element for, variants (the widget hint `variants`).
 */
export function Field(props: FieldProps): JSX.Element | null {
  const extension = props.schema['x-surface'];
  switch (extension?.widget) {
    case 'select':
      return extension.multiple ? <MultipleSelectField {...props} /> : <SelectField {...props} />;
    case 'radios':
      return <RadiosField {...props} />;
    case 'checkboxes':
      return <CheckboxesField {...props} />;
    case 'checkbox':
      return <CheckboxField {...props} />;
    case 'number':
      return <NumberField {...props} />;
    case 'text':
    case 'email':
    case 'time':
      return <TextField {...props} />;
    case 'textarea':
      return <TextareaField {...props} />;
    case 'fieldset':
      return <FieldsetField {...props} />;
    case 'variants':
      return <VariantsField {...props} />;
    case 'list':
      return <ListField {...props} />;
    case 'fixed':
      return <FixedField {...props} />;
    case 'autocomplete':
      return <AutocompleteField {...props} />;
    default:
      return <UnsupportedField {...props} />;
  }
}

/** A module's fieldset drawn outside the group it sits in, and where its values live. */
interface Placed {
  group: string;
  name: string;
  schema: Schema;
}

/**
 * The fieldsets inside this object's groups that an alter placed after
 * one of this object's own properties (`x-surface.after`), by that
 * property. A placement naming no sibling, or the group itself, is left
 * where it sits.
 */
function placements(schema: Schema): Record<string, Placed[]> {
  const properties = schema.properties ?? {};
  const placed: Record<string, Placed[]> = {};
  for (const [group, property] of Object.entries(properties)) {
    if (!property['x-surface']?.group) {
      continue;
    }
    for (const [name, child] of Object.entries(property.properties ?? {})) {
      const after = child['x-surface']?.after;
      if (after !== undefined && after !== group && after in properties) {
        (placed[after] ??= []).push({ group, name, schema: child });
      }
    }
  }
  return placed;
}

/** A group's schema without the fieldsets drawn elsewhere. */
function withoutPlaced(group: Schema, name: string, placed: Record<string, Placed[]>): Schema {
  const moved = new Set(
    Object.values(placed)
      .flat()
      .filter((entry) => entry.group === name)
      .map((entry) => entry.name),
  );
  if (moved.size === 0) {
    return group;
  }
  return { ...group, properties: Object.fromEntries(Object.entries(group.properties ?? {}).filter(([child]) => !moved.has(child))) };
}

/**
 * The properties of an object schema, each as its own field.
 *
 * A module's fieldset its alter placed (`x-surface.after`) is drawn right
 * after the named property instead of inside its group, and keeps its
 * path: its values are still posted under the group, where they are
 * stored. A group left with nothing to draw is drawn as nothing.
 */
export function Properties({
  schema,
  values,
  prefix,
  onChange,
  errors,
  disabled,
}: {
  schema: Schema;
  values: Values;
  prefix: string;
  onChange: FieldProps['onChange'];
  errors: FieldProps['errors'];
  disabled?: boolean;
}): JSX.Element {
  const placed = placements(schema);
  const field = (name: string, property: Schema, at: Schema, held: Values, path: string, locked?: boolean) => (
    <Field
      key={path}
      name={name}
      path={path}
      schema={property}
      value={held[name]}
      required={(at.required ?? []).includes(name)}
      onChange={onChange}
      errors={errors}
      parent={at}
      parentValues={held}
      disabled={locked}
    />
  );
  const drawnAll = Object.entries(schema.properties ?? {}).map(([name, property]): Arranged => {
    const drawn = property['x-surface']?.group ? withoutPlaced(property, name, placed) : property;
    const empty = drawn !== property && Object.keys(drawn.properties ?? {}).length === 0;
    return {
      arrangement: property['x-surface']?.arrangement,
      elements: [
        empty ? null : field(name, drawn, schema, values, prefix + name, disabled),
        ...(placed[name] ?? []).map((entry) => {
          const group = schema.properties?.[entry.group] ?? {};
          const held = values[entry.group];
          return field(
            entry.name,
            entry.schema,
            group,
            isObject(held) ? held : {},
            `${prefix}${entry.group}.${entry.name}`,
            disabled || Boolean(group.readOnly),
          );
        }),
      ],
    };
  });
  return <>{arranged(drawnAll, prefix)}</>;
}

/** One property's elements, and how its arrangement says to draw them. */
interface Arranged {
  arrangement?: Arrangement;
  elements: (JSX.Element | null)[];
}

/** Sorts by weight, lighter first, keeping declaration order between equals. */
function byWeight<T>(entries: T[], weight: (entry: T) => number): T[] {
  return entries.map((entry, position) => ({ entry, position })).sort((a, b) => weight(a.entry) - weight(b.entry) || a.position - b.position).map(({ entry }) => entry);
}

/**
 * Draws a frame's properties as their arrangements say, as the form
 * builder does: each group where its first key is declared, a fieldset or
 * a details holding its keys, ordered among the frame's keys by its own
 * weight; each key by its weight within its group, or within the frame.
 * Only drawing: every key keeps its path.
 */
function arranged(entries: Arranged[], prefix: string): (JSX.Element | null)[] {
  type Top = { weight: number; group: ArrangementGroup; members: Arranged[] } | { weight: number; group: null; entry: Arranged };
  const top: Top[] = [];
  const groups = new Map<string, Top & { group: ArrangementGroup }>();
  for (const entry of entries) {
    const group = entry.arrangement?.group;
    if (group === undefined) {
      top.push({ weight: entry.arrangement?.weight ?? 0, group: null, entry });
      continue;
    }
    let drawn = groups.get(group.id);
    if (drawn === undefined) {
      drawn = { weight: group.weight ?? 0, group, members: [] };
      groups.set(group.id, drawn);
      top.push(drawn);
    }
    drawn.members.push(entry);
  }
  return byWeight(top, (entry) => entry.weight).flatMap((entry) => {
    if (entry.group === null) {
      return entry.entry.elements;
    }
    const members = byWeight(entry.members, (member) => member.arrangement?.weight ?? 0).flatMap((member) => member.elements);
    return [<ArrangedGroup key={`${prefix}@group.${entry.group.id}`} group={entry.group} id={fieldId(`${prefix}@group.${entry.group.id}`)}>{members}</ArrangedGroup>];
  });
}

/** A group a frame's arrangement names: a fieldset, or a details a person may close. */
function ArrangedGroup({ group, id, children }: { group: ArrangementGroup; id: string; children: (JSX.Element | null)[] }): JSX.Element {
  if (group.as === 'details') {
    return (
      <details className="dsr-details" id={id} open={!group.collapsed}>
        <summary className="dsr-summary">{group.label}</summary>
        {children}
      </details>
    );
  }
  return (
    <fieldset className="dsr-fieldset dsr-arranged" id={id}>
      <legend className="dsr-legend">{group.label}</legend>
      {children}
    </fieldset>
  );
}

/**
 * A map, an attached part, or a chosen variant: a fieldset whose legend is
 * its title, holding one field per property. An object that only groups
 * (`x-surface.group`, the modules' third-party settings) is its fields
 * and nothing around them, as the form draws it.
 */
export function FieldsetField(props: FieldProps): JSX.Element {
  const id = fieldId(props.path);
  if (props.schema['x-surface']?.group) {
    return (
      <div className="dsr-group" id={id}>
        <Properties
          schema={props.schema}
          values={isObject(props.value) ? props.value : {}}
          prefix={`${props.path}.`}
          onChange={props.onChange}
          errors={props.errors}
          disabled={isLocked(props)}
        />
      </div>
    );
  }
  return (
    <fieldset className="dsr-fieldset" id={id}>
      <legend className="dsr-legend">
        {props.schema.title ?? props.name}
        {props.required ? <RequiredMarker /> : null}
      </legend>
      <Description props={props} id={id} />
      <Errors props={props} id={id} />
      <Properties
        schema={props.schema}
        values={isObject(props.value) ? props.value : {}}
        prefix={`${props.path}.`}
        onChange={props.onChange}
        errors={props.errors}
        disabled={isLocked(props)}
      />
    </fieldset>
  );
}

/**
 * Variants (the widget hint `variants`): the fieldset of the variant their
 * deciding key chose, read from the parent's conditionals, and nothing
 * at all while nothing is chosen, as the form builder renders no element
 * for variants nothing has chosen yet. A variant that declares no keys
 * (a choice with nothing more to say) is drawn as nothing too, as the
 * form builder draws it. The variant says nothing of the key it fills:
 * the key with variants says whether it is locked, and the variant's
 * fieldset is locked with it.
 */
export function VariantsField(props: FieldProps): JSX.Element | null {
  const variant = props.parent === undefined ? null : resolveVariants(props.parent, props.name, props.parentValues ?? {});
  if (variant === null || Object.keys(variant.properties ?? {}).length === 0) {
    return null;
  }
  return <FieldsetField {...props} schema={variant} disabled={isLocked(props)} />;
}

/** What a new row of a list starts as. */
function newItem(items: Schema): unknown {
  if (items.default !== undefined) {
    return items.default;
  }
  switch (jsonType(items)) {
    case 'object':
      return {};
    case 'string':
      return '';
    case 'boolean':
      return false;
    default:
      return null;
  }
}

/**
 * A list with no option list: a repeatable, rows added and removed.
 *
 * A collection (`x-surface.collection`) draws each item as the child's
 * fieldset, titled with the list's title and the item's number, at least
 * `minItems` of them; removing stops at `minItems` and adding at
 * `maxItems`. Its items' schema is the child's def, dereferenced.
 */
export function ListField(props: FieldProps): JSX.Element {
  const id = fieldId(props.path);
  const items = props.schema.items ?? {};
  const collection = Boolean(props.schema['x-surface']?.collection);
  const atLeast = collection ? props.schema.minItems ?? 0 : 0;
  const held = Array.isArray(props.value) ? props.value : [];
  const rows = held.length >= atLeast ? held : [...held, ...Array.from({ length: atLeast - held.length }, () => ({}))];
  const locked = isLocked(props);
  const atMost = props.schema.maxItems;
  const title = props.schema.title ?? props.name;
  return (
    <fieldset className="dsr-fieldset dsr-list" id={id}>
      <legend className="dsr-legend">
        {props.schema.title ?? props.name}
        {props.required ? <RequiredMarker /> : null}
      </legend>
      <Description props={props} id={id} />
      <Errors props={props} id={id} />
      <ol className="dsr-list__rows">
        {rows.map((row, index) => (
          <li key={index} className="dsr-list__row">
            {collection ? (
              <FieldsetField
                name={String(index)}
                path={`${props.path}.${index}`}
                schema={{ ...items, title: `${title} ${index + 1}` }}
                value={isObject(row) ? row : {}}
                required={false}
                onChange={props.onChange}
                errors={props.errors}
                disabled={locked}
              />
            ) : (
              <Field
                name={String(index)}
                path={`${props.path}.${index}`}
                schema={{ ...items, title: `${items.title ?? title} ${index + 1}` }}
                value={row}
                required={false}
                onChange={props.onChange}
                errors={props.errors}
                disabled={locked}
              />
            )}
            <button
              type="button"
              className="dsr-button dsr-button--small"
              disabled={locked || rows.length <= atLeast}
              onClick={() => props.onChange(props.path, rows.filter((_, position) => position !== index))}
            >
              Remove {index + 1}
            </button>
          </li>
        ))}
      </ol>
      <button
        type="button"
        className="dsr-button dsr-button--small"
        disabled={locked || (atMost !== undefined && rows.length >= atMost)}
        onClick={() => props.onChange(props.path, [...rows, collection ? {} : newItem(items)])}
      >
        {collection ? 'Add another' : `Add ${props.schema.title ? props.schema.title.toLowerCase() : 'a row'}`}
      </button>
    </fieldset>
  );
}
