FROM node:24.18.0-bookworm-slim AS development
WORKDIR /workspace
COPY package.json package-lock.json* .npmrc ./
COPY apps/admin-web/package.json apps/admin-web/package.json
COPY apps/driver-mobile/package.json apps/driver-mobile/package.json
COPY packages/api-contracts/package.json packages/api-contracts/package.json
COPY packages/localization/package.json packages/localization/package.json
COPY packages/shared-config/package.json packages/shared-config/package.json
COPY packages/shared-types/package.json packages/shared-types/package.json
RUN npm install
COPY . .
EXPOSE 3000

FROM development AS build
RUN npm run web:build

FROM node:24.18.0-bookworm-slim AS production
ENV NODE_ENV=production
WORKDIR /workspace
COPY --from=build /workspace ./
USER node
CMD ["npm", "run", "start", "--workspace=@oromia/admin-web"]
