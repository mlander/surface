import type { Schema, Values } from '../contract';
import { fieldId, isObject, jsonType, resolveSlot } from '../contract';
import {
  CheckboxField,
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
 * definition to an element: options to a select, a map to a fieldset, a
 * string to a text input or a textarea, a number to a number input, a
 * boolean to a checkbox; and the two the Form API has no element for, a
 * slot and a list.
 */
export function Field(props: FieldProps): JSX.Element | null {
  const extension = props.schema['x-surface'];
  switch (extension?.widget) {
    case 'select':
      return extension.multiple ? <MultipleSelectField {...props} /> : <SelectField {...props} />;
    case 'radios':
      return <RadiosField {...props} />;
    case 'checkbox':
      return <CheckboxField {...props} />;
    case 'number':
      return <NumberField {...props} />;
    case 'text':
    case 'email':
      return <TextField {...props} />;
    case 'textarea':
      return <TextareaField {...props} />;
    case 'fieldset':
      return <FieldsetField {...props} />;
    case 'slot':
      return <SlotField {...props} />;
    case 'list':
      return <ListField {...props} />;
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
  return (
    <>
      {Object.entries(schema.properties ?? {}).flatMap(([name, property]) => {
        const drawn = property['x-surface']?.group ? withoutPlaced(property, name, placed) : property;
        const empty = drawn !== property && Object.keys(drawn.properties ?? {}).length === 0;
        return [
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
              disabled || Boolean(group['x-surface']?.locked || group.readOnly),
            );
          }),
        ];
      })}
    </>
  );
}

/**
 * A map, an attached part, or a resolved slot: a fieldset whose legend is
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
 * A slot: the fieldset of the variant its deciding key chose, read from
 * the parent's conditionals, and nothing at all while nothing is chosen,
 * as the form builder renders no element for an unresolved slot.
 */
export function SlotField(props: FieldProps): JSX.Element | null {
  const variant = props.parent === undefined ? null : resolveSlot(props.parent, props.name, props.parentValues ?? {});
  if (variant === null) {
    return null;
  }
  return <FieldsetField {...props} schema={variant} />;
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

/** A list with no option list: a minimal repeatable, rows added and removed. */
export function ListField(props: FieldProps): JSX.Element {
  const id = fieldId(props.path);
  const items = props.schema.items ?? {};
  const rows = Array.isArray(props.value) ? props.value : [];
  const locked = isLocked(props);
  const atMost = props.schema.maxItems;
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
            <Field
              name={String(index)}
              path={`${props.path}.${index}`}
              schema={{ ...items, title: `${items.title ?? props.schema.title ?? props.name} ${index + 1}` }}
              value={row}
              required={false}
              onChange={props.onChange}
              errors={props.errors}
              disabled={locked}
            />
            <button
              type="button"
              className="dsr-button dsr-button--small"
              disabled={locked}
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
        onClick={() => props.onChange(props.path, [...rows, newItem(items)])}
      >
        Add {props.schema.title ? props.schema.title.toLowerCase() : 'a row'}
      </button>
    </fieldset>
  );
}
