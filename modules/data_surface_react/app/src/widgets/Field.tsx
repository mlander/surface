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

/** The properties of an object schema, each as its own field. */
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
  return (
    <>
      {Object.entries(schema.properties ?? {}).map(([name, property]) => (
        <Field
          key={name}
          name={name}
          path={prefix + name}
          schema={property}
          value={values[name]}
          required={(schema.required ?? []).includes(name)}
          onChange={onChange}
          errors={errors}
          parent={schema}
          parentValues={values}
          disabled={disabled}
        />
      ))}
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
