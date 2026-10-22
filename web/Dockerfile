# ---------------------------------------------------------------------------
# SPA Vue — dibangun dengan Vite, dilayani nginx.
#
# Proxy /api diteruskan ke container `api-web`, sehingga browser hanya perlu
# tahu satu origin (port 3000). Ini menghindari CORS sepenuhnya pada demo.
# ---------------------------------------------------------------------------

FROM node:22-alpine AS build

WORKDIR /app

COPY package.json package-lock.json* ./
RUN npm install --no-audit --no-fund

COPY . .
RUN npm run build

FROM nginx:1.27-alpine

COPY --from=build /app/dist /usr/share/nginx/html
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf

EXPOSE 80
