import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { createApi, pageOf, SEND_FINGERPRINT, type Fetcher, type Settings } from './api';
import type { Contract, Narrowed, Schema, Settled, Values, Violation } from './contract';
import { ContractRefused, inlineSchema, joinedFetch, narrow, narrowingStatus, watchedPaths, setAt, withoutComputed } from './contract';
import { ContractPanel } from './ContractPanel';
import { Properties } from './widgets/Field';
import { LookupContext } from './widgets/Shell';

/** How long a change waits before the narrowing it asks of the server is sent: the request path only. */
export const REFINE_DELAY = 300;

function same(a: unknown, b: unknown): boolean {
  return JSON.stringify(a) === JSON.stringify(b);
}

/**
 * The browser's fetch, as one function for the life of the page: the API
 * is built from it, so a new function per render would be a new API per
 * render, and the contract would be fetched again on every one.
 */
const browserFetch: Fetcher = (input, init) => fetch(input, init);

/** Moves the browser to another page: where a created thing now lives. */
const browserNavigate = (url: string): void => window.location.assign(url);

/** What the summary above the form says: a rehearsal's or a write's answer. */
interface Summary {
  /** `run` for an accepted run that wrote nothing: an operation that only reads. */
  kind: 'submit' | 'run';
  valid: boolean;
  violations: Violation[];
  notes: Violation[];
  stale: Violation[];
}

/** Files violations by path, for the fields to show inline. */
function byPath(violations: Violation[]): Record<string, string[]> {
  const errors: Record<string, string[]> = {};
  for (const violation of violations) {
    (errors[violation.path] ??= []).push(violation.message);
  }
  return errors;
}

/**
 * One operation of one resource, as a form rendered from its contract.
 *
 * The contract for the stored values, with the ETag of what is stored,
 * and the full schema, which reads no value, are fetched together, once.
 * On a change of any key something depends on, the form narrows itself
 * from the full schema's conditionals when every key that depends on the
 * change is stated there, by the rules the server's narrowing applies,
 * with no request. A key `deferred` for its size is stated by the full
 * schema fetched for the value chosen (its `fetch`, with the choices of
 * every other deferred key the change reaches joined in), kept in memory
 * for the page's life, and the form narrows from that, then from it for
 * the changes after. When one is not stated (`refine`, `live`, a stale value only
 * the server knows), or anything cannot be settled here, the current
 * values are posted to the schema (debounced) and the form re-renders
 * from the contract that comes back, the way the operation form rebuilds
 * over AJAX: a value the person has touched since the request left is
 * kept, everything else takes the server's answer, so an orphaned
 * dependent comes back on its empty option, standing for the stored
 * value the server kept. Either way an orphan is listed stale and sent
 * back by its path. The contract panel says which it was. Submit writes
 * the values, `If-Match` the ETag of what was stored when the contract
 * was loaded: a refusal shows beside each field and in a summary, with
 * nothing written; a write says so, with any stale value it kept, and
 * re-renders from the contract the answer carries, its ETag the next
 * write's; a write that created something moves to the page where it
 * now lives. A document of any contract but 3 is refused, and the
 * refusal shown as the form's error.
 */
export function SurfaceForm({
  settings,
  fetcher = browserFetch,
  refineDelay = REFINE_DELAY,
  navigate = browserNavigate,
}: {
  settings: Settings;
  fetcher?: Fetcher;
  refineDelay?: number;
  navigate?: (url: string) => void;
}): JSX.Element {
  const api = useMemo(() => createApi(settings, fetcher), [settings, fetcher]);
  const [contract, setContract] = useState<Contract | null>(null);
  // The form's schema for its values, inline: the server's last answer,
  // or what was narrowed here since.
  const [schema, setSchema] = useState<Schema | null>(null);
  // The full schema as served, for the panel; null when it did not load,
  // and then every change is asked of the server.
  const [fullDocument, setFullDocument] = useState<Schema | null>(null);
  const [narrowing, setNarrowing] = useState<string | null>(null);
  const [values, setValues] = useState<Values>({});
  const [failure, setFailure] = useState<string | null>(null);
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [summary, setSummary] = useState<Summary | null>(null);
  const [refining, setRefining] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  const current = useRef<Values>({});
  const stale = useRef<string[]>([]);
  // The ETag of what storage held when the contract was loaded, or last
  // written, as received; a narrowing re-reads storage, so it never
  // replaces this.
  const fingerprint = useRef<string | null>(null);
  // What the operation was loaded with, or last written: what is stored
  // over the defaults, which a dropped answer falls back to.
  const stored = useRef<Values>({});
  // The schema the form shows and the values it was settled for.
  const settled = useRef<Settled | null>(null);
  // Whether a narrowing is out; one scheduled has a timer. A change
  // meanwhile joins it.
  const pending = useRef(false);
  const timer = useRef<ReturnType<typeof setTimeout> | undefined>(undefined);
  const sequence = useRef(0);
  // The full schema fetched for the choices made, which states the deferred
  // keys they choose; null until a choice needs one.
  const chosenFull = useRef<Schema | null>(null);

  // Value-independent, so resolved once for the page.
  const full = useMemo(() => (fullDocument === null ? null : inlineSchema(fullDocument)), [fullDocument]);

  const show = useCallback((next: Contract, kept: Values) => {
    const inline = inlineSchema(next.schema);
    settled.current = { schema: inline, values: next.values, stale: next.stale };
    current.current = kept;
    stale.current = next.stale;
    setContract(next);
    setSchema(inline);
    setValues(kept);
  }, []);

  useEffect(() => {
    let live = true;
    // Two requests, once: the contract for the stored values, and the full
    // schema, which is the same whatever is stored or typed and so is never
    // asked for again. Without it the form asks the server for every
    // narrowing; a full schema of another contract is refused as the
    // contract would be.
    const loading = api.contract();
    const full = api.schema().catch((error: Error) => {
      if (error instanceof ContractRefused) {
        throw error;
      }
      return null;
    });
    Promise.all([loading, full]).then(
      ([loaded, loadedFull]) => {
        if (live) {
          fingerprint.current = loaded.etag;
          stored.current = loaded.document.values;
          setFullDocument(loadedFull);
          show(loaded.document, loaded.document.values);
        }
      },
      (error: Error) => live && setFailure(error.message),
    );
    return () => {
      live = false;
      clearTimeout(timer.current);
    };
  }, [api, show]);

  const refine = useCallback(async () => {
    timer.current = undefined;
    const sent = current.current;
    const mine = ++sequence.current;
    pending.current = true;
    setRefining(true);
    try {
      const refined = await api.narrow(sent, stale.current);
      if (mine !== sequence.current) {
        return;
      }
      // What the person changed while the request was out is theirs;
      // the rest is the server's answer, discards included.
      const kept: Values = { ...refined.values };
      for (const [key, value] of Object.entries(current.current)) {
        if (!same(value, sent[key])) {
          kept[key] = value;
        }
      }
      show(refined, kept);
    }
    catch (error) {
      setFailure((error as Error).message);
    }
    finally {
      if (mine === sequence.current) {
        setRefining(false);
        pending.current = false;
      }
    }
  }, [api, show]);

  // Narrows here, or says why the server must: no full schema, a request
  // already on its way (which this change joins), or a dependent the full
  // schema does not state.
  const decide = useCallback((path: string): Narrowed => {
    if (pending.current || timer.current !== undefined) {
      return { local: false, key: path, marker: 'pending' };
    }
    if (full === null || settled.current === null) {
      return { local: false, key: path, marker: 'unsettled' };
    }
    return narrow({ full: chosenFull.current ?? full, previous: settled.current, stored: stored.current, values: current.current, stale: stale.current, changed: path });
  }, [full]);

  // A change a deferred key depends on: the full schema with the choice
  // stated is fetched (once per set of choices), and the change narrowed
  // from it; a deferred key it reaches in turn joins its choice to the next
  // fetch. Anything still not stated, or a fetch that fails, is asked of
  // the server as before.
  const fetchChosen = useCallback(async (path: string, first: Narrowed): Promise<Narrowed> => {
    const mine = ++sequence.current;
    pending.current = true;
    setRefining(true);
    let decided = first;
    const fetches: string[] = [];
    try {
      while (!decided.local && decided.marker === 'deferred' && decided.fetch !== undefined && fetches.length < 8) {
        fetches.push(decided.fetch);
        const fetched = inlineSchema(await api.chosen(joinedFetch(fetches, settings.endpoints.schema)));
        if (mine !== sequence.current || settled.current === null) {
          // A later change took it over.
          return { local: false, key: path, marker: 'pending' };
        }
        decided = narrow({ full: fetched, previous: settled.current, stored: stored.current, values: current.current, stale: stale.current, changed: path });
        if (decided.local) {
          chosenFull.current = fetched;
          settled.current = decided.settled;
          current.current = decided.settled.values;
          stale.current = decided.settled.stale;
          setSchema(decided.settled.schema);
          setValues(decided.settled.values);
          setNarrowing(narrowingStatus(decided, true));
        }
      }
    }
    catch {
      decided = { local: false, key: path, marker: 'unsettled' };
    }
    finally {
      if (mine === sequence.current) {
        setRefining(false);
        pending.current = false;
      }
    }
    return decided;
  }, [api, settings]);

  // The contract defines each part once and refers to it; the form reads
  // every key inline, resolved once per contract or narrowing, not once
  // per render.
  // The full schema's too: a key that does not apply now is no property
  // of the form's schema, and what it depends on still moves it.
  const watched = useMemo(() => {
    const paths = schema === null ? new Set<string>() : watchedPaths(schema, values);
    if (full !== null) {
      watchedPaths(full, values).forEach((path) => paths.add(path));
    }
    return paths;
  }, [schema, full, values]);

  const onChange = useCallback(
    (path: string, value: unknown) => {
      current.current = setAt(current.current, path, value);
      setValues(current.current);
      // An explicit answer replaces a stale value: it no longer stands
      // for what is stored.
      stale.current = stale.current.filter((dotted) => dotted !== path && !dotted.startsWith(`${path}.`));
      setErrors((previous) => {
        if (previous[path] === undefined) {
          return previous;
        }
        const next = { ...previous };
        delete next[path];
        return next;
      });
      if (watched.has(path)) {
        const decided = decide(path);
        if (decided.local) {
          setNarrowing(narrowingStatus(decided));
          settled.current = decided.settled;
          current.current = decided.settled.values;
          stale.current = decided.settled.stale;
          setSchema(decided.settled.schema);
          setValues(decided.settled.values);
          return;
        }
        if (decided.marker === 'deferred' && decided.fetch !== undefined) {
          void fetchChosen(path, decided).then((answered) => {
            if (!answered.local && answered.marker !== 'pending') {
              setNarrowing(narrowingStatus(answered));
              clearTimeout(timer.current);
              timer.current = setTimeout(() => void refine(), refineDelay);
            }
          });
          return;
        }
        setNarrowing(narrowingStatus(decided));
        clearTimeout(timer.current);
        timer.current = setTimeout(() => void refine(), refineDelay);
      }
    },
    [watched, refine, refineDelay, decide, fetchChosen],
  );

  const submit = useCallback(async () => {
    clearTimeout(timer.current);
    timer.current = undefined;
    setSubmitting(true);
    try {
      const send = settings.sendFingerprint ?? SEND_FINGERPRINT;
      const sent = settled.current === null ? current.current : withoutComputed(settled.current.schema, current.current);
      const { document: result, etag } = await api.submit(sent, stale.current, send ? fingerprint.current : null);
      setFailure(null);
      setErrors(byPath(result.violations));
      // An operation that only reads answers its run accepted, and writes
      // nothing: valid, but not saved.
      const kind = result.ok && !result.committed ? 'run' : 'submit';
      setSummary({ kind, valid: result.ok, violations: result.violations, notes: result.notes, stale: result.stale });
      if (!result.committed) {
        return;
      }
      if (result.created !== null) {
        navigate(pageOf(settings, result.created));
        return;
      }
      if (result.contract !== null) {
        // A narrowing still out was asked of what is now overwritten.
        sequence.current++;
        setRefining(false);
        pending.current = false;
        fingerprint.current = etag;
        stored.current = result.contract.values;
        show(result.contract, result.contract.values);
      }
    }
    catch (error) {
      setFailure((error as Error).message);
    }
    finally {
      setSubmitting(false);
    }
  }, [api, settings, navigate, show]);

  if (contract === null || schema === null) {
    return failure === null
      ? <p className="dsr-status">Loading the form…</p>
      : <div className="dsr-summary dsr-summary--error" role="alert">The form could not be loaded: {failure}</div>;
  }

  return (
    <form
      className="dsr"
      noValidate
      onSubmit={(event) => {
        event.preventDefault();
        void submit();
      }}
      aria-busy={refining || submitting || undefined}
    >
      {failure !== null ? (
        <div className="dsr-summary dsr-summary--error" role="alert">
          {failure}
        </div>
      ) : null}
      {summary !== null ? (
        <div className={`dsr-summary ${summary.valid ? 'dsr-summary--valid' : 'dsr-summary--error'}`} role="status">
          {summary.valid ? (
            summary.kind === 'submit' ? (
              <p>Saved: the values were written.</p>
            ) : (
              <p>Valid: these values would be accepted. Nothing was written.</p>
            )
          ) : (
            <>
              <p>
                {summary.violations.length === 1 ? 'One value was refused' : `${summary.violations.length} values were refused`}. Nothing was
                written.
              </p>
              <ul>
                {summary.violations.map((violation) => (
                  <li key={`${violation.path}:${violation.message}`}>
                    {violation.path === '' ? null : (
                      <>
                        <code>{violation.path}</code>:{' '}
                      </>
                    )}
                    {violation.message}
                  </li>
                ))}
              </ul>
            </>
          )}
          {summary.notes.length > 0 ? (
            <ul className="dsr-summary__notes">
              {summary.notes.map((note) => (
                <li key={note.path}>
                  <code>{note.path}</code>: {note.message}
                </li>
              ))}
            </ul>
          ) : null}
          {summary.stale.length > 0 ? (
            <ul className="dsr-summary__stale">
              {summary.stale.map((reference) => (
                <li key={reference.path}>
                  <code>{reference.path}</code>: {reference.message}
                </li>
              ))}
            </ul>
          ) : null}
        </div>
      ) : null}
      {/* Locked whole: every field is fixed, so there is nothing to submit. */}
      {contract.schema.readOnly ? (
        <p className="dsr-status">This may be seen and not changed.</p>
      ) : null}
      <LookupContext.Provider value={api.lookup}>
        <Properties schema={schema} values={values} prefix="" onChange={onChange} errors={errors} />
      </LookupContext.Provider>
      {contract.schema.readOnly ? null : (
        <div className="dsr-actions">
          <button type="submit" className="dsr-button dsr-button--primary" disabled={submitting}>
            Submit
          </button>
        </div>
      )}
      <ContractPanel contract={contract} full={fullDocument} schema={schema} values={values} narrowing={narrowing} />
    </form>
  );
}
