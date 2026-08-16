# ---- Stage 1: Build the React SPA ----
FROM node:20-alpine AS build

WORKDIR /app

# Install dependencies first (layer caching)
COPY package.json package-lock.json ./
RUN npm ci

# Build the app. VITE_* build args are baked into the static bundle at build time.
# In production set this to https://api.marqira.com (Coolify build variable).
ARG VITE_API_BASE_URL=""
ENV VITE_API_BASE_URL=$VITE_API_BASE_URL

COPY . .
RUN npm run build

# ---- Stage 2: Serve static files with Nginx ----
FROM nginx:1.27-alpine AS runtime

# SPA-aware Nginx config (history fallback + security headers)
COPY nginx.conf /etc/nginx/conf.d/default.conf

# Copy the compiled assets from the build stage
COPY --from=build /app/dist /usr/share/nginx/html

EXPOSE 80

CMD ["nginx", "-g", "daemon off;"]
