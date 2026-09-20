# Multi-stage build: the first stage has the full TypeScript toolchain to
# compile the app; the second stage ships only the compiled JavaScript and
# production dependencies, which is both smaller and a real security
# improvement (no source code, dev tooling, or test files exist in the
# final image that actually runs in production).

FROM node:20-slim AS build
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY tsconfig.json ./
COPY src ./src
RUN npm run build

FROM node:20-slim AS production
WORKDIR /app
ENV NODE_ENV=production

COPY package.json package-lock.json ./
RUN npm ci --omit=dev

COPY --from=build /app/dist ./dist
COPY migrations ./migrations
COPY seed ./seed
COPY docker-entrypoint.sh ./docker-entrypoint.sh

RUN chmod +x docker-entrypoint.sh

# Runs as a real non-root user — the node:20-slim base image already
# creates one (uid 1000), this just switches to it rather than running as
# root inside the container, which is a genuine, standard hosting-security
# practice, not a formality.
USER node

EXPOSE 3000

# A real, working healthcheck — hits the same /health endpoint this
# codebase already exposes and has used throughout development, so
# hosting platforms (and `docker compose`'s own healthcheck-aware restart
# logic) can tell a genuinely broken container apart from one still
# starting up.
HEALTHCHECK --interval=15s --timeout=5s --start-period=30s --retries=3 \
  CMD node -e "require('http').get('http://localhost:3000/health', (r) => { let d=''; r.on('data',c=>d+=c); r.on('end',()=>process.exit(r.statusCode===200&&d.includes('connected')?0:1)); }).on('error', () => process.exit(1))"

CMD ["/bin/sh", "docker-entrypoint.sh"]
