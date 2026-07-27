"use client";

import { translate, type Locale } from "@oromia/localization";
import { useState, type FormEvent } from "react";
import type { FleetDocumentSummary } from "@/fleet/types";
import { apiRequest } from "@/platform/api-client";

type UploadState = "idle" | "uploading" | "accepted" | "error";

export function FleetDocumentsPanel({
  documents,
  locale,
  organizationId,
  ownerId,
  ownerType,
  onUploaded,
}: {
  readonly documents: readonly FleetDocumentSummary[];
  readonly locale: Locale;
  readonly organizationId: string;
  readonly ownerId: string;
  readonly ownerType: "vehicle" | "driver";
  readonly onUploaded: () => void;
}) {
  const t = (key: Parameters<typeof translate>[1]) => translate(locale, key);
  const [uploadState, setUploadState] = useState<UploadState>("idle");

  async function upload(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setUploadState("uploading");
    const formElement = event.currentTarget;
    const form = new FormData(formElement);
    form.set("organization_id", organizationId);
    form.set("owner_type", ownerType);
    form.set("owner_id", ownerId);
    form.set("classification", "internal");
    try {
      await apiRequest("/documents", { method: "POST", body: form });
      formElement.reset();
      setUploadState("accepted");
      onUploaded();
    } catch {
      setUploadState("error");
    }
  }

  return (
    <section className="fleet-documents">
      <div className="fleet-documents-heading">
        <div>
          <p className="eyebrow">{t("fleet.evidenceEyebrow")}</p>
          <h2>{t("fleet.documentsTitle")}</h2>
          <p>{t("fleet.documentsDetail")}</p>
        </div>
        <span className="semantic-badge badge-neutral">
          {documents.length} {t("fleet.records")}
        </span>
      </div>

      {documents.length ? (
        <ul className="fleet-document-list">
          {documents.map((document) => (
            <li key={document.id}>
              <div className="fleet-document-mark" aria-hidden="true">
                {document.document_type === "VEHICLE_PHOTOGRAPH" ? "◎" : "▤"}
              </div>
              <div>
                <strong>
                  {document.original_filename ??
                    document.document_type.replaceAll("_", " ")}
                </strong>
                <span>
                  {document.category.replaceAll("_", " ")} ·{" "}
                  {document.classification}
                </span>
              </div>
              <span
                className={`semantic-badge badge-${
                  document.trust_status === "trusted"
                    ? "success"
                    : document.status === "rejected"
                      ? "warning"
                      : "neutral"
                }`}
              >
                {document.trust_status ?? document.status}
              </span>
            </li>
          ))}
        </ul>
      ) : (
        <p className="fleet-documents-empty">{t("fleet.documentsEmpty")}</p>
      )}

      <form className="fleet-document-upload" onSubmit={upload}>
        <label>
          <span>{t("fleet.documentType")}</span>
          <select name="document_type_code" required>
            {ownerType === "vehicle" ? (
              <>
                <option value="VEHICLE_PHOTOGRAPH">
                  {t("fleet.vehiclePhotograph")}
                </option>
                <option value="VEHICLE_COMPLIANCE">
                  {t("fleet.complianceDocument")}
                </option>
                <option value="VEHICLE_ATTACHMENT">
                  {t("fleet.vehicleAttachment")}
                </option>
              </>
            ) : (
              <>
                <option value="DRIVER_LICENCE">
                  {t("fleet.driverLicenceDocument")}
                </option>
                <option value="DRIVER_DOCUMENT">
                  {t("fleet.driverSupportingDocument")}
                </option>
              </>
            )}
          </select>
        </label>
        <label>
          <span>{t("fleet.documentCategory")}</span>
          <input
            defaultValue={
              ownerType === "vehicle" ? "asset_evidence" : "licence"
            }
            maxLength={80}
            name="category"
            required
          />
        </label>
        <label className="fleet-file-field">
          <span>{t("fleet.chooseFile")}</span>
          <input
            accept={
              ownerType === "vehicle"
                ? "image/jpeg,image/png,application/pdf"
                : "image/jpeg,image/png,application/pdf"
            }
            name="file"
            required
            type="file"
          />
        </label>
        <button disabled={uploadState === "uploading"} type="submit">
          {uploadState === "uploading"
            ? t("fleet.uploadingDocument")
            : t("fleet.uploadDocument")}
        </button>
      </form>
      <div aria-live="polite" className="fleet-document-feedback">
        {uploadState === "accepted" && t("fleet.documentAccepted")}
        {uploadState === "error" && t("fleet.documentUploadError")}
      </div>
    </section>
  );
}
