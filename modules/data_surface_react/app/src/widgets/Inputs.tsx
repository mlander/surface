import { choices, fieldId, isEmpty, jsonType, sameValue } from '../contract';
import { describedBy, Description, Errors, isLocked, Item, RequiredMarker, type FieldProps } from './Shell';

/**
 * A single select, under the empty option rule.
 *
 * Whether the empty option is shown, and under which label, is the
 * contract's answer (`x-surface.emptyOption`): always for an optional
 * select, and for a required one only while no valid choice is selected.
 * A stale value comes up on the empty option, selected, and is never put
 * back into the list; a required select standing for one keeps its
 * marker and is not required to the browser, since empty there means
 * "keep". Options are matched by index, so an integer value comes back
 * an integer.
 */
export function SelectField(props: FieldProps): JSX.Element {
  const extension = props.schema['x-surface'];
  const options = choices(props.schema);
  const index = options.findIndex((choice) => sameValue(choice.const, props.value));
  const stale = Boolean(extension?.stale);
  const empty = extension?.emptyOption ?? { show: !props.required, label: props.required ? '- Select -' : '- None -' };
  const id = fieldId(props.path);
  return (
    <Item props={props} marker={props.required}>
      <select
        id={id}
        name={props.path}
        value={index === -1 ? '' : String(index)}
        disabled={isLocked(props)}
        required={props.required && !stale}
        aria-describedby={describedBy(props, id)}
        aria-invalid={(props.errors[props.path] ?? []).length > 0 || undefined}
        onChange={(event) => props.onChange(props.path, event.target.value === '' ? null : options[Number(event.target.value)].const)}
      >
        {empty.show || index === -1 ? <option value="">{empty.label}</option> : null}
        {options.map((choice, position) => (
          <option key={String(choice.const)} value={String(position)} title={choice.description}>
            {choice.title ?? String(choice.const)}
          </option>
        ))}
      </select>
    </Item>
  );
}

/** A multiple select: a list whose items each name one option. */
export function MultipleSelectField(props: FieldProps): JSX.Element {
  const options = choices(props.schema.items ?? {});
  const selected = Array.isArray(props.value) ? props.value : [];
  const id = fieldId(props.path);
  return (
    <Item props={props} marker={props.required}>
      <select
        id={id}
        name={props.path}
        multiple
        size={Math.min(Math.max(options.length, 2), 8)}
        value={options.flatMap((choice, position) => (selected.some((value) => sameValue(value, choice.const)) ? [String(position)] : []))}
        disabled={isLocked(props)}
        aria-describedby={describedBy(props, id)}
        onChange={(event) =>
          props.onChange(
            props.path,
            Array.from(event.target.selectedOptions, (option) => options[Number(option.value)].const),
          )
        }
      >
        {options.map((choice, position) => (
          <option key={String(choice.const)} value={String(position)}>
            {choice.title ?? String(choice.const)}
          </option>
        ))}
      </select>
    </Item>
  );
}

/**
 * Radios over the same options a select offers.
 *
 * In the vocabulary for a contract that asks for it; the module's own
 * emitter never does, since the Form API mapping renders no radios.
 */
export function RadiosField(props: FieldProps): JSX.Element {
  const extension = props.schema['x-surface'];
  const options = choices(props.schema);
  const id = fieldId(props.path);
  const empty = extension?.emptyOption ?? { show: !props.required, label: '- None -' };
  const entries = [
    ...(empty.show ? [{ key: '', label: empty.label, value: null as unknown }] : []),
    ...options.map((choice) => ({ key: String(choice.const), label: choice.title ?? String(choice.const), value: choice.const })),
  ];
  return (
    <fieldset className="dsr-item dsr-item--radios" id={id} aria-describedby={describedBy(props, id)}>
      <legend className="dsr-label">
        {props.schema.title ?? props.name}
        {props.required ? <RequiredMarker /> : null}
      </legend>
      {entries.map((entry) => (
        <label key={entry.key} className="dsr-option">
          <input
            type="radio"
            name={props.path}
            value={entry.key}
            checked={entry.value === null ? isEmpty(props.value) : sameValue(entry.value, props.value)}
            disabled={isLocked(props)}
            onChange={() => props.onChange(props.path, entry.value)}
          />{' '}
          {entry.label}
        </label>
      ))}
      <Description props={props} id={id} />
      <Errors props={props} id={id} />
    </fieldset>
  );
}

/**
 * A checkbox. Never marked required: a required boolean asks only for
 * presence, which a checkbox always gives, as the boolean widget says.
 */
export function CheckboxField(props: FieldProps): JSX.Element {
  const id = fieldId(props.path);
  return (
    <div className="dsr-item dsr-item--checkbox">
      <label className="dsr-option" htmlFor={id}>
        <input
          type="checkbox"
          id={id}
          name={props.path}
          checked={Boolean(props.value)}
          disabled={isLocked(props)}
          aria-describedby={describedBy(props, id)}
          onChange={(event) => props.onChange(props.path, event.target.checked)}
        />{' '}
        {props.schema.title ?? props.name}
      </label>
      <Description props={props} id={id} />
      <Errors props={props} id={id} />
    </div>
  );
}

/**
 * Whether a field stands for a stored value it shows as nothing.
 *
 * An orphan of a refine, whatever its widget: empty there means "keep",
 * so the field is not required to the browser, and its label keeps the
 * marker. The path goes back with the next refine, validate or submit.
 */
function standsForStored(props: FieldProps): boolean {
  return Boolean(props.schema['x-surface']?.stale);
}

/** A number input: Range's bounds as min and max, a step by type. */
export function NumberField(props: FieldProps): JSX.Element {
  const id = fieldId(props.path);
  const example = props.schema.examples?.[0];
  return (
    <Item props={props} marker={props.required}>
      <input
        type="number"
        id={id}
        name={props.path}
        value={isEmpty(props.value) ? '' : String(props.value)}
        min={props.schema.minimum}
        max={props.schema.maximum}
        step={jsonType(props.schema) === 'integer' ? 1 : 'any'}
        placeholder={example === undefined ? undefined : String(example)}
        required={props.required && !standsForStored(props)}
        disabled={isLocked(props)}
        aria-describedby={describedBy(props, id)}
        aria-invalid={(props.errors[props.path] ?? []).length > 0 || undefined}
        onChange={(event) => {
          const raw = event.target.value;
          // Sent as typed when it is not a number: the pipeline casts
          // what means the same and refuses the rest, with its message.
          props.onChange(props.path, raw === '' ? null : Number.isNaN(Number(raw)) ? raw : Number(raw));
        }}
      />
    </Item>
  );
}

/**
 * A text input: email or url by type, a password for a secret.
 *
 * Length's max is the maxlength and the first example the placeholder;
 * a secret is never shown a value and gets no placeholder.
 */
export function TextField(props: FieldProps): JSX.Element {
  const id = fieldId(props.path);
  const secret = Boolean(props.schema.writeOnly);
  const widget = props.schema['x-surface']?.widget;
  const type = secret ? 'password' : widget === 'email' ? 'email' : props.schema.format === 'uri' ? 'url' : 'text';
  const example = props.schema.examples?.[0];
  return (
    <Item props={props} marker={props.required}>
      <input
        type={type}
        id={id}
        name={props.path}
        value={isEmpty(props.value) ? '' : String(props.value)}
        maxLength={props.schema.maxLength}
        placeholder={secret || example === undefined ? undefined : String(example)}
        required={props.required && !secret && !standsForStored(props)}
        disabled={isLocked(props)}
        autoComplete={secret ? 'new-password' : undefined}
        aria-describedby={describedBy(props, id)}
        aria-invalid={(props.errors[props.path] ?? []).length > 0 || undefined}
        onChange={(event) => props.onChange(props.path, event.target.value)}
      />
    </Item>
  );
}

/** A textarea: no maxlength and no placeholder, as the string widget. */
export function TextareaField(props: FieldProps): JSX.Element {
  const id = fieldId(props.path);
  return (
    <Item props={props} marker={props.required}>
      <textarea
        id={id}
        name={props.path}
        rows={5}
        value={isEmpty(props.value) ? '' : String(props.value)}
        required={props.required && !standsForStored(props)}
        disabled={isLocked(props)}
        aria-describedby={describedBy(props, id)}
        aria-invalid={(props.errors[props.path] ?? []).length > 0 || undefined}
        onChange={(event) => props.onChange(props.path, event.target.value)}
      />
    </Item>
  );
}

/** What a key no widget claims shows, as the Form API would refuse it. */
export function UnsupportedField(props: FieldProps): JSX.Element {
  return (
    <div className="dsr-item dsr-item--none">
      <div className="dsr-label">{props.schema.title ?? props.name}</div>
      <div className="dsr-description">Not editable here: no widget claims this key.</div>
    </div>
  );
}
