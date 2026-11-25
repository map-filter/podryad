FROM node:22-alpine AS frontend
WORKDIR /app
COPY frontend/package.json frontend/package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY frontend/ ./
RUN npm run build

FROM node:22-alpine AS docs
WORKDIR /docs
RUN npm install --no-audit --no-fund --prefix /docs swagger-ui-dist@5.17.14

FROM nginx:alpine
COPY docker/gateway/nginx.conf /etc/nginx/conf.d/default.conf
COPY --from=frontend /app/dist /usr/share/nginx/html
COPY --from=docs /docs/node_modules/swagger-ui-dist /usr/share/nginx/docs-api
COPY docker/gateway/docs-index.html /usr/share/nginx/docs-api/index.html
COPY docs/openapi.yaml /usr/share/nginx/docs-api/openapi.yaml
