# OptiStash console image: the built SPA behind nginx. Build context: repository root.
# The SPA calls the API on the same origin (/api/ is proxied to the API), so the image has no environment-specific URL baked in and
# the session cookie and CSRF token stay first-party.
ARG NODE_IMAGE=node:24-alpine
ARG NGINX_IMAGE=nginx:1.27-alpine

FROM ${NODE_IMAGE} AS build
WORKDIR /app
COPY frontend/package.json frontend/package-lock.json ./
RUN npm ci
COPY frontend/. .
RUN npm run build

FROM ${NGINX_IMAGE}
COPY docker/frontend/nginx.conf.template /etc/nginx/templates/default.conf.template
COPY --from=build /app/dist /usr/share/nginx/html
ENV API_UPSTREAM=http://web:8400 \
    LISTEN_PORT=80
EXPOSE 80
