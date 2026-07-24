# Oromia Fleet Management and Vehicle Asset Control System

Enterprise monorepo for the Oromia Finance Bureau platform. Milestone 1 contains platform foundations only—no organization, IAM/RBAC, fleet, trip, finance, maintenance, inventory, risk or reporting implementation.

## Applications and packages

- `apps/api`: Laravel 13 REST platform API.
- `apps/admin-web`: Next.js 16 TypeScript administrative shell.
- `apps/driver-mobile`: Expo SDK 57 Android driver shell.
- `packages/api-contracts`: OpenAPI-generated TypeScript types.
- `packages/localization`: English source and human-review Afaan Oromoo/Amharic catalogues.
- `packages/shared-types` and `shared-config`: framework-neutral contracts and strict TypeScript defaults.

## Prerequisites

PHP 8.4.1+ with DOM/XML/cURL/mbstring/PDO MySQL, Composer 2.10, Node 24 LTS with npm, Docker Engine and Docker Compose, and an Android development environment for the driver app.

## Quick start

```bash
cp apps/api/.env.example apps/api/.env
cp apps/admin-web/.env.example apps/admin-web/.env.local
cp apps/driver-mobile/.env.example apps/driver-mobile/.env
composer --working-dir=apps/api install
php apps/api/artisan key:generate
npm install
docker compose up -d mysql redis mailpit minio clamav
php apps/api/artisan migrate
php apps/api/artisan serve --port=8080
npm run web:dev
npm run mobile:start
```

In separate terminals run `php apps/api/artisan queue:work` and `php apps/api/artisan schedule:work`. Full setup, validation and shutdown commands are in `docs/development/local-setup.md`.

Never commit `.env`, credentials, signing keys, production data or certificates.
