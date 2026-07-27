"use client";

import { translate, type Locale } from "@oromia/localization";
import Link from "next/link";
import {
  useCallback,
  useEffect,
  useMemo,
  useState,
  type FormEvent,
  type InputHTMLAttributes,
} from "react";
import type {
  ApiEnvelope,
  DistanceReferenceSummary,
  GeographyImportSummary,
  GeographyDashboard,
  OperationalZoneSummary,
  PagedEnvelope,
  PlaceCategory,
  PlaceSummary,
  RouteSummary,
} from "@/geography/types";
import { ApiProblem, apiRequest } from "@/platform/api-client";

type Register = "places" | "routes" | "distances" | "zones" | "imports";
type LoadState =
  | "context"
  | "loading"
  | "ready"
  | "empty"
  | "forbidden"
  | "error";
type CommandState = "idle" | "saving" | "saved" | "error";

export function GeographyWorkspace({
  locale,
  organizationId,
}: {
  readonly locale: Locale;
  readonly organizationId?: string;
}) {
  const t = useCallback(
    (key: Parameters<typeof translate>[1]) => translate(locale, key),
    [locale],
  );
  const [register, setRegister] = useState<Register>("places");
  const [state, setState] = useState<LoadState>(
    organizationId ? "loading" : "context",
  );
  const [commandState, setCommandState] = useState<CommandState>("idle");
  const [dashboard, setDashboard] = useState<GeographyDashboard | null>(null);
  const [categories, setCategories] = useState<readonly PlaceCategory[]>([]);
  const [places, setPlaces] = useState<readonly PlaceSummary[]>([]);
  const [routes, setRoutes] = useState<readonly RouteSummary[]>([]);
  const [distances, setDistances] = useState<
    readonly DistanceReferenceSummary[]
  >([]);
  const [zones, setZones] = useState<readonly OperationalZoneSummary[]>([]);
  const [imports, setImports] = useState<readonly GeographyImportSummary[]>([]);
  const [query, setQuery] = useState("");
  const [status, setStatus] = useState("");
  const [refresh, setRefresh] = useState(0);

  useEffect(() => {
    if (!organizationId) return;
    let active = true;
    const filters = new URLSearchParams({ organization_id: organizationId });
    if (query) filters.set("query", query);
    if (status) filters.set("status", status);
    Promise.all([
      apiRequest<ApiEnvelope<GeographyDashboard>>(
        `/geography/dashboard?organization_id=${organizationId}`,
      ),
      apiRequest<ApiEnvelope<readonly PlaceCategory[]>>(
        `/geography/reference-data/place-categories?organization_id=${organizationId}`,
      ),
      apiRequest<PagedEnvelope<PlaceSummary>>(`/places?${filters}`),
      apiRequest<PagedEnvelope<RouteSummary>>(`/routes?${filters}`),
      apiRequest<PagedEnvelope<DistanceReferenceSummary>>(
        `/distance-references?organization_id=${organizationId}`,
      ),
      apiRequest<ApiEnvelope<readonly OperationalZoneSummary[]>>(
        `/operational-zones?organization_id=${organizationId}`,
      ),
      apiRequest<PagedEnvelope<GeographyImportSummary>>(
        `/distance-imports?organization_id=${organizationId}`,
      ),
    ])
      .then(
        ([
          summary,
          categoryData,
          placeData,
          routeData,
          distanceData,
          zoneData,
          importData,
        ]) => {
          if (!active) return;
          setDashboard(summary.data);
          setCategories(categoryData.data);
          setPlaces(placeData.data);
          setRoutes(routeData.data);
          setDistances(distanceData.data);
          setZones(zoneData.data);
          setImports(importData.data);
          setState(
            placeData.data.length +
              routeData.data.length +
              distanceData.data.length +
              zoneData.data.length +
              importData.data.length ===
              0
              ? "empty"
              : "ready",
          );
        },
      )
      .catch((error: unknown) => {
        if (!active) return;
        setState(
          error instanceof ApiProblem && error.problem.status === 403
            ? "forbidden"
            : "error",
        );
      });
    return () => {
      active = false;
    };
  }, [organizationId, query, status, refresh]);

  const metrics = useMemo(
    () =>
      [
        [t("geography.totalPlaces"), dashboard?.places.total ?? 0, "primary"],
        [t("geography.activeRoutes"), dashboard?.routes.active ?? 0, "success"],
        [
          t("geography.distanceLegs"),
          dashboard?.distance_references.legs ?? 0,
          "information",
        ],
        [
          t("geography.operationalZones"),
          dashboard?.operational_zones ?? 0,
          "warning",
        ],
      ] as const,
    [dashboard, t],
  );

  if (!organizationId) {
    return (
      <section className="geo-context-state" aria-labelledby="geography-title">
        <span aria-hidden="true">OG</span>
        <div>
          <p className="eyebrow">{t("geography.eyebrow")}</p>
          <h1 id="geography-title">{t("geography.title")}</h1>
          <p>{t("geography.contextRequired")}</p>
          <Link className="primary-button" href="/organizations">
            {t("geography.chooseOrganization")}
          </Link>
        </div>
      </section>
    );
  }

  const reload = () => {
    setCommandState("saved");
    setRefresh((value) => value + 1);
  };

  return (
    <div className="geo-workspace">
      <nav className="breadcrumbs" aria-label={t("nav.breadcrumbs")}>
        <Link href="/">{t("nav.home")}</Link>
        <span aria-hidden="true">/</span>
        <span aria-current="page">{t("geography.title")}</span>
      </nav>

      <header className="geo-command-header">
        <div>
          <p className="eyebrow">{t("geography.eyebrow")}</p>
          <h1>{t("geography.title")}</h1>
          <p>{t("geography.description")}</p>
        </div>
        <div className="geo-authority-seal" aria-label={t("geography.serverAuthoritative")}>
          <span aria-hidden="true">✓</span>
          <strong>{t("geography.serverAuthoritative")}</strong>
          <small>{organizationId}</small>
        </div>
      </header>

      <section className="geo-metrics" aria-label={t("geography.snapshot")}>
        {metrics.map(([label, value, tone]) => (
          <article className={`geo-metric geo-${tone}`} key={label}>
            <span>{label}</span>
            <strong>{value}</strong>
            <i aria-hidden="true" />
          </article>
        ))}
      </section>

      <section className="geo-register-shell">
        <div className="geo-register-heading">
          <div>
            <span className="step-label">{t("geography.register")}</span>
            <h2>{t(`geography.${register}`)}</h2>
          </div>
          <span className="geo-live-stamp">
            <i aria-hidden="true" />
            {t("geography.serverAuthoritative")}
          </span>
        </div>

        <div className="geo-tabs" role="tablist">
          {(["places", "routes", "distances", "zones", "imports"] as const).map(
            (item) => (
              <button
                aria-selected={register === item}
                key={item}
                onClick={() => {
                  setRegister(item);
                  setCommandState("idle");
                }}
                role="tab"
                type="button"
              >
                <span aria-hidden="true">{tabIcon(item)}</span>
                {t(`geography.${item}`)}
                <small>{recordCount(item, places, routes, distances, zones, imports)}</small>
              </button>
            ),
          )}
        </div>

        {register !== "imports" && (
          <form
            className="geo-filter-bar"
            onSubmit={(event: FormEvent<HTMLFormElement>) => {
              event.preventDefault();
              const data = new FormData(event.currentTarget);
              setState("loading");
              setQuery(String(data.get("query") ?? "").trim());
              setStatus(String(data.get("status") ?? ""));
            }}
          >
            <label>
              <span>{t("geography.search")}</span>
              <input
                defaultValue={query}
                name="query"
                placeholder={t("geography.searchPlaceholder")}
                type="search"
              />
            </label>
            <label>
              <span>{t("geography.status")}</span>
              <select defaultValue={status} name="status">
                <option value="">{t("geography.allStatuses")}</option>
                <option value="active">{t("geography.active")}</option>
                <option value="draft">{t("geography.draft")}</option>
                <option value="inactive">{t("geography.inactive")}</option>
              </select>
            </label>
            <button type="submit">{t("geography.applyFilters")}</button>
          </form>
        )}

        <div aria-busy={state === "loading"} aria-live="polite">
          {state === "loading" && (
            <GeographySkeleton label={t("state.loading")} />
          )}
          {state === "forbidden" && (
            <GeographyState
              detail={t("geography.forbiddenDetail")}
              title={t("state.forbidden")}
              tone="danger"
            />
          )}
          {state === "error" && (
            <GeographyState
              detail={t("geography.errorDetail")}
              title={t("state.unavailable")}
              tone="warning"
            />
          )}
          {(state === "ready" || state === "empty") && (
            <div className="geo-content-grid">
              <div className="geo-record-column">
                {register === "places" && (
                  <PlaceRegister locale={locale} places={places} />
                )}
                {register === "routes" && (
                  <RouteRegister locale={locale} routes={routes} />
                )}
                {register === "distances" && (
                  <DistanceRegister
                    distances={distances}
                    locale={locale}
                    onApproved={() => setRefresh((value) => value + 1)}
                  />
                )}
                {register === "zones" && (
                  <ZoneRegister locale={locale} zones={zones} />
                )}
                {register === "imports" && (
                  <ImportAssurance
                    batches={imports}
                    locale={locale}
                    onChanged={() => setRefresh((value) => value + 1)}
                  />
                )}
                {recordCount(register, places, routes, distances, zones, imports) ===
                  0 &&
                  register !== "imports" && (
                    <GeographyState
                      detail={t("geography.noRecordsDetail")}
                      title={t("geography.noRecords")}
                      tone="neutral"
                    />
                  )}
              </div>
              <GeographyCommandPanel
                categories={categories}
                commandState={commandState}
                locale={locale}
                onError={() => setCommandState("error")}
                onSaved={reload}
                onSaving={() => setCommandState("saving")}
                organizationId={organizationId}
                places={places}
                register={register}
              />
            </div>
          )}
        </div>
      </section>
    </div>
  );
}

function GeographyCommandPanel({
  register,
  locale,
  organizationId,
  places,
  categories,
  commandState,
  onSaving,
  onSaved,
  onError,
}: {
  readonly register: Register;
  readonly locale: Locale;
  readonly organizationId: string;
  readonly places: readonly PlaceSummary[];
  readonly categories: readonly PlaceCategory[];
  readonly commandState: CommandState;
  readonly onSaving: () => void;
  readonly onSaved: () => void;
  readonly onError: () => void;
}) {
  const t = (key: Parameters<typeof translate>[1]) => translate(locale, key);
  const submit = async (
    event: FormEvent<HTMLFormElement>,
    path: string,
    map: (data: FormData) => Record<string, unknown>,
  ) => {
    event.preventDefault();
    onSaving();
    try {
      await apiRequest(path, {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          "Idempotency-Key": crypto.randomUUID(),
        },
        body: JSON.stringify(map(new FormData(event.currentTarget))),
      });
      event.currentTarget.reset();
      onSaved();
    } catch {
      onError();
    }
  };
  const effectiveFrom = new Date().toISOString().slice(0, 16);

  return (
    <aside className="geo-command-panel">
      <div>
        <span className="step-label">
          {
            {
              places: t("geography.newPlace"),
              routes: t("geography.newRoute"),
              distances: t("geography.newDistance"),
              zones: t("geography.newZone"),
              imports: t("geography.stageImport"),
            }[register]
          }
        </span>
        <h3>{t(`geography.${register}`)}</h3>
      </div>
      {commandState === "saved" && (
        <div className="geo-command-feedback success" role="status">
          <strong>{t("geography.saved")}</strong>
          <span>{t("geography.savedDetail")}</span>
        </div>
      )}
      {commandState === "error" && (
        <div className="geo-command-feedback danger" role="alert">
          <strong>{t("state.validation")}</strong>
          <span>{t("geography.errorDetail")}</span>
        </div>
      )}

      {register === "places" && (
        <form
          onSubmit={(event) =>
            submit(event, "/places", (data) => ({
              code: data.get("code"),
              name: names(data),
              place_category_id: data.get("category"),
              owning_organization_id: organizationId,
              administrative_organization_id: organizationId,
              latitude: numberOrNull(data.get("latitude")),
              longitude: numberOrNull(data.get("longitude")),
              timezone: "Africa/Addis_Ababa",
              effective_from: data.get("effective_from"),
              status: "draft",
              address: {
                address_type: "physical",
                country_code: "ET",
              },
              organization_mappings: [
                {
                  organization_id: organizationId,
                  mapping_role: "owner",
                  is_primary: true,
                },
              ],
            }))
          }
        >
          <TextField label={t("geography.code")} name="code" required />
          <NameFields locale={locale} />
          <label>
            <span>{t("geography.category")}</span>
            <select name="category" required>
              <option value="">{t("geography.category")}</option>
              {categories.map((category) => (
                <option key={category.id} value={category.id}>
                  {localized(category.name, locale)}
                </option>
              ))}
            </select>
          </label>
          <div className="geo-coordinate-fields">
            <TextField
              inputMode="decimal"
              label={t("geography.latitude")}
              max="90"
              min="-90"
              name="latitude"
              step="0.0000001"
              type="number"
            />
            <TextField
              inputMode="decimal"
              label={t("geography.longitude")}
              max="180"
              min="-180"
              name="longitude"
              step="0.0000001"
              type="number"
            />
          </div>
          <DateField
            defaultValue={effectiveFrom}
            label={t("geography.effectiveFrom")}
          />
          <SubmitButton locale={locale} state={commandState} />
        </form>
      )}

      {register === "routes" && (
        <form
          onSubmit={(event) =>
            submit(event, "/routes", (data) => {
              const origin = String(data.get("origin"));
              const destination = String(data.get("destination"));
              const distance = Number(data.get("distance"));
              const duration = Number(data.get("duration"));
              return {
                organization_id: organizationId,
                code: data.get("code"),
                name: names(data),
                origin_place_id: origin,
                destination_place_id: destination,
                directional: data.get("directional") === "on",
                version: {
                  alternative_label: data.get("alternative"),
                  preferred: true,
                  estimated_distance_km: distance,
                  estimated_duration_minutes: duration,
                  source_type: "bureau_matrix",
                  source_reference: data.get("source"),
                  effective_from: data.get("effective_from"),
                  segments: [
                    {
                      sequence: 1,
                      origin_place_id: origin,
                      destination_place_id: destination,
                      distance_km: distance,
                      duration_minutes: duration,
                      mandatory_stop: false,
                    },
                  ],
                },
              };
            })
          }
        >
          <TextField label={t("geography.code")} name="code" required />
          <NameFields locale={locale} />
          <PlacePair locale={locale} places={places} />
          <TextField
            label={t("geography.routeAlternative")}
            name="alternative"
            required
          />
          <MetricFields locale={locale} />
          <TextField
            label={t("geography.sourceReference")}
            name="source"
            required
          />
          <DateField
            defaultValue={effectiveFrom}
            label={t("geography.effectiveFrom")}
          />
          <label className="geo-check">
            <input defaultChecked name="directional" type="checkbox" />
            <span>{t("geography.directional")}</span>
          </label>
          <SubmitButton locale={locale} state={commandState} />
        </form>
      )}

      {register === "distances" && (
        <form
          onSubmit={(event) =>
            submit(event, "/distance-references", (data) => ({
              organization_id: organizationId,
              code: data.get("code"),
              name: data.get("name_en"),
              source_type: "bureau_matrix",
              source_reference: data.get("source"),
              effective_from: data.get("effective_from"),
              status: "draft",
              legs: [
                {
                  origin_place_id: data.get("origin"),
                  destination_place_id: data.get("destination"),
                  route_label: data.get("alternative"),
                  distance_km: Number(data.get("distance")),
                  estimated_duration_minutes: Number(data.get("duration")),
                  directional: data.get("directional") === "on",
                  tolerance_percent: 5,
                },
              ],
            }))
          }
        >
          <TextField label={t("geography.code")} name="code" required />
          <TextField label={t("geography.nameEn")} name="name_en" required />
          <PlacePair locale={locale} places={places} />
          <TextField
            label={t("geography.routeAlternative")}
            name="alternative"
            required
          />
          <MetricFields locale={locale} />
          <TextField
            label={t("geography.sourceReference")}
            name="source"
            required
          />
          <DateField
            defaultValue={effectiveFrom}
            label={t("geography.effectiveFrom")}
          />
          <label className="geo-check">
            <input name="directional" type="checkbox" />
            <span>{t("geography.directional")}</span>
          </label>
          <SubmitButton locale={locale} state={commandState} />
        </form>
      )}

      {register === "zones" && (
        <form
          onSubmit={(event) =>
            submit(event, "/operational-zones", (data) => ({
              organization_id: organizationId,
              code: data.get("code"),
              name: names(data),
              zone_type: data.get("zone_type"),
              effective_from: data.get("effective_from"),
              status: "active",
              places: [],
            }))
          }
        >
          <TextField label={t("geography.code")} name="code" required />
          <NameFields locale={locale} />
          <label>
            <span>{t("geography.zoneType")}</span>
            <select name="zone_type" required>
              <option value="service">{t("geography.zones")}</option>
              <option value="administrative">{t("geography.places")}</option>
              <option value="restricted">{t("geography.inactive")}</option>
              <option value="custom">{t("geography.category")}</option>
            </select>
          </label>
          <DateField
            defaultValue={effectiveFrom}
            label={t("geography.effectiveFrom")}
          />
          <SubmitButton locale={locale} state={commandState} />
        </form>
      )}

      {register === "imports" && (
        <form
          onSubmit={(event) =>
            submit(event, "/distance-imports", (data) => ({
              organization_id: organizationId,
              import_type: data.get("import_type"),
              document_id: data.get("document_id"),
            }))
          }
        >
          <p className="geo-import-note">{t("geography.importTrust")}</p>
          <TextField
            label={t("geography.documentId")}
            maxLength={26}
            minLength={26}
            name="document_id"
            required
          />
          <label>
            <span>{t("geography.imports")}</span>
            <select name="import_type" required>
              <option value="places">{t("geography.places")}</option>
              <option value="routes">{t("geography.routes")}</option>
              <option value="distance_matrix">
                {t("geography.distances")}
              </option>
            </select>
          </label>
          <SubmitButton locale={locale} state={commandState} />
        </form>
      )}
    </aside>
  );
}

function PlaceRegister({
  places,
  locale,
}: {
  readonly places: readonly PlaceSummary[];
  readonly locale: Locale;
}) {
  const t = (key: Parameters<typeof translate>[1]) => translate(locale, key);
  return (
    <div className="geo-card-list">
      {places.map((place) => (
        <article className="geo-place-card" key={place.id}>
          <div className="geo-record-mark" aria-hidden="true">
            {place.category?.classification === "administrative" ? "A" : "P"}
          </div>
          <div>
            <span>{place.code}</span>
            <h3>{localized(place.name, locale)}</h3>
            <p>
              {place.category
                ? localized(place.category.name, locale)
                : t("geography.category")}
            </p>
          </div>
          <div className="geo-coordinate">
            {place.latitude && place.longitude ? (
              <>
                <strong>{place.latitude}</strong>
                <span>{place.longitude}</span>
              </>
            ) : (
              <span className="geo-coordinate-warning">
                {t("geography.coordinatesMissing")}
              </span>
            )}
          </div>
          <StatusBadge locale={locale} status={place.status} />
        </article>
      ))}
    </div>
  );
}

function RouteRegister({
  routes,
  locale,
}: {
  readonly routes: readonly RouteSummary[];
  readonly locale: Locale;
}) {
  const t = (key: Parameters<typeof translate>[1]) => translate(locale, key);
  return (
    <div className="geo-card-list">
      {routes.map((route) => {
        const version = route.versions[0];
        return (
          <article className="geo-route-card" key={route.id}>
            <div className="geo-route-line" aria-hidden="true">
              <i />
              <span />
              <i />
            </div>
            <div>
              <span>{route.code}</span>
              <h3>{localized(route.name, locale)}</h3>
              <p>{t("geography.routeIntegrity")}</p>
            </div>
            {version && (
              <div className="geo-route-facts">
                <strong>{version.estimated_distance_km} km</strong>
                <span>{version.estimated_duration_minutes} min</span>
                <small>{version.alternative_label}</small>
              </div>
            )}
            <StatusBadge
              locale={locale}
              status={version?.status ?? route.status}
            />
          </article>
        );
      })}
    </div>
  );
}

function DistanceRegister({
  distances,
  locale,
  onApproved,
}: {
  readonly distances: readonly DistanceReferenceSummary[];
  readonly locale: Locale;
  readonly onApproved: () => void;
}) {
  const t = (key: Parameters<typeof translate>[1]) => translate(locale, key);
  const [approving, setApproving] = useState<string | null>(null);
  return (
    <div className="geo-card-list">
      {distances.map((distance) => (
        <article className="geo-distance-card" key={distance.id}>
          <div className="geo-record-mark" aria-hidden="true">
            km
          </div>
          <div>
            <span>{distance.code}</span>
            <h3>{distance.name}</h3>
            <p>
              {distance.legs_count} {t("geography.recordCount")} ·{" "}
              {t("geography.distanceSource")}
            </p>
          </div>
          <StatusBadge locale={locale} status={distance.status} />
          {distance.status === "draft" && (
            <button
              className="geo-approve-button"
              disabled={approving === distance.id}
              onClick={async () => {
                setApproving(distance.id);
                try {
                  await apiRequest(
                    `/distance-references/${distance.id}/approve`,
                    {
                      method: "POST",
                      headers: {
                        "Content-Type": "application/json",
                        "Idempotency-Key": crypto.randomUUID(),
                      },
                      body: JSON.stringify({
                        record_version: distance.record_version,
                      }),
                    },
                  );
                  onApproved();
                } finally {
                  setApproving(null);
                }
              }}
              type="button"
            >
              {t("geography.approve")}
            </button>
          )}
        </article>
      ))}
    </div>
  );
}

function ZoneRegister({
  zones,
  locale,
}: {
  readonly zones: readonly OperationalZoneSummary[];
  readonly locale: Locale;
}) {
  return (
    <div className="geo-zone-grid">
      {zones.map((zone) => (
        <article key={zone.id}>
          <span>{zone.code}</span>
          <h3>{localized(zone.name, locale)}</h3>
          <p>{zone.zone_type.replaceAll("_", " ")}</p>
          <StatusBadge locale={locale} status={zone.status} />
        </article>
      ))}
    </div>
  );
}

function ImportAssurance({
  batches,
  locale,
  onChanged,
}: {
  readonly batches: readonly GeographyImportSummary[];
  readonly locale: Locale;
  readonly onChanged: () => void;
}) {
  const t = (key: Parameters<typeof translate>[1]) => translate(locale, key);
  const [working, setWorking] = useState<string | null>(null);
  const command = async (
    batchId: string,
    action: "approve" | "rollback",
    reason?: string,
  ) => {
    setWorking(batchId);
    try {
      await apiRequest(`/distance-imports/${batchId}/${action}`, {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          "Idempotency-Key": crypto.randomUUID(),
        },
        body: JSON.stringify(reason ? { reason } : {}),
      });
      onChanged();
    } finally {
      setWorking(null);
    }
  };

  return (
    <div className="geo-card-list">
      {batches.length === 0 && (
        <div className="geo-import-assurance">
          <span aria-hidden="true">CSV</span>
          <div>
            <h3>{t("geography.stageImport")}</h3>
            <p>{t("geography.importTrust")}</p>
          </div>
        </div>
      )}
      {batches.map((batch) => (
        <article className="geo-distance-card" key={batch.id}>
          <div className="geo-record-mark" aria-hidden="true">CSV</div>
          <div>
            <span>{batch.import_type.replaceAll("_", " ")}</span>
            <h3>{batch.source_name}</h3>
            <p>{t("geography.rows")}: {batch.row_count} · {t("geography.validRows")}: {batch.valid_row_count} · {t("geography.invalidRows")}: {batch.invalid_row_count}</p>
            <small>{t("geography.sourceChecksum")}: {batch.source_checksum}</small>
          </div>
          <StatusBadge locale={locale} status={batch.status} />
          {batch.status === "validated" && (
            <button
              disabled={working === batch.id}
              onClick={() => void command(batch.id, "approve")}
              type="button"
            >
              {t("action.approve")}
            </button>
          )}
          {batch.status === "approved_applied_draft" && (
            <form
              onSubmit={(event) => {
                event.preventDefault();
                const reason = String(new FormData(event.currentTarget).get("reason") ?? "");
                void command(batch.id, "rollback", reason);
              }}
            >
              <TextField label={t("geography.rollbackReason")} minLength={3} name="reason" required />
              <button disabled={working === batch.id} type="submit">{t("geography.rollback")}</button>
            </form>
          )}
        </article>
      ))}
    </div>
  );
}

function NameFields({ locale }: { readonly locale: Locale }) {
  const t = (key: Parameters<typeof translate>[1]) => translate(locale, key);
  return (
    <>
      <TextField label={t("geography.nameEn")} name="name_en" required />
      <TextField label={t("geography.nameOm")} name="name_om" required />
      <TextField label={t("geography.nameAm")} name="name_am" required />
    </>
  );
}

function PlacePair({
  places,
  locale,
}: {
  readonly places: readonly PlaceSummary[];
  readonly locale: Locale;
}) {
  const t = (key: Parameters<typeof translate>[1]) => translate(locale, key);
  return (
    <div className="geo-coordinate-fields">
      {(["origin", "destination"] as const).map((name) => (
        <label key={name}>
          <span>{t(`geography.${name}`)}</span>
          <select name={name} required>
            <option value="">{t(`geography.${name}`)}</option>
            {places.map((place) => (
              <option key={place.id} value={place.id}>
                {localized(place.name, locale)}
              </option>
            ))}
          </select>
        </label>
      ))}
    </div>
  );
}

function MetricFields({ locale }: { readonly locale: Locale }) {
  const t = (key: Parameters<typeof translate>[1]) => translate(locale, key);
  return (
    <div className="geo-coordinate-fields">
      <TextField
        label={t("geography.distanceKm")}
        min="0.01"
        name="distance"
        required
        step="0.01"
        type="number"
      />
      <TextField
        label={t("geography.durationMinutes")}
        min="1"
        name="duration"
        required
        type="number"
      />
    </div>
  );
}

function TextField({
  label,
  ...props
}: { readonly label: string } & InputHTMLAttributes<HTMLInputElement>) {
  return (
    <label>
      <span>{label}</span>
      <input {...props} />
    </label>
  );
}

function DateField({
  label,
  defaultValue,
}: {
  readonly label: string;
  readonly defaultValue: string;
}) {
  return (
    <label>
      <span>{label}</span>
      <input
        defaultValue={defaultValue}
        name="effective_from"
        required
        type="datetime-local"
      />
    </label>
  );
}

function SubmitButton({
  locale,
  state,
}: {
  readonly locale: Locale;
  readonly state: CommandState;
}) {
  return (
    <button className="geo-submit" disabled={state === "saving"} type="submit">
      {translate(
        locale,
        state === "saving" ? "geography.saving" : "geography.submit",
      )}
    </button>
  );
}

function StatusBadge({
  status,
  locale,
}: {
  readonly status: string;
  readonly locale: Locale;
}) {
  const key =
    status === "active" || status === "approved"
      ? "geography.active"
      : status === "draft"
        ? "geography.draft"
        : "geography.inactive";
  return (
    <span className={`geo-status geo-status-${status}`}>
      <i aria-hidden="true" />
      {translate(locale, key)}
    </span>
  );
}

function GeographyState({
  title,
  detail,
  tone,
}: {
  readonly title: string;
  readonly detail: string;
  readonly tone: "neutral" | "warning" | "danger";
}) {
  return (
    <div className={`geo-state geo-state-${tone}`} role="status">
      <span aria-hidden="true">{tone === "neutral" ? "○" : "!"}</span>
      <div>
        <strong>{title}</strong>
        <p>{detail}</p>
      </div>
    </div>
  );
}

function GeographySkeleton({ label }: { readonly label: string }) {
  return (
    <div
      aria-label={label}
      className="geo-skeleton"
      role="status"
    >
      <span />
      <span />
      <span />
    </div>
  );
}

function localized(
  name: Readonly<Record<"en" | "om" | "am", string>>,
  locale: Locale,
): string {
  return name[locale] || name.en;
}

function names(data: FormData) {
  return {
    en: String(data.get("name_en") ?? ""),
    om: String(data.get("name_om") ?? ""),
    am: String(data.get("name_am") ?? ""),
  };
}

function numberOrNull(value: FormDataEntryValue | null) {
  return value === null || value === "" ? null : Number(value);
}

function tabIcon(register: Register) {
  return {
    places: "⌖",
    routes: "↝",
    distances: "↔",
    zones: "⬡",
    imports: "⇧",
  }[register];
}

function recordCount(
  register: Register,
  places: readonly PlaceSummary[],
  routes: readonly RouteSummary[],
  distances: readonly DistanceReferenceSummary[],
  zones: readonly OperationalZoneSummary[],
  imports: readonly GeographyImportSummary[],
) {
  return {
    places: places.length,
    routes: routes.length,
    distances: distances.length,
    zones: zones.length,
    imports: imports.length,
  }[register];
}
