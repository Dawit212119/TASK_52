# When Docker Hub is blocked/slow, set DOCKER_IMAGE_REGISTRY_PREFIX in repo/.env (trailing slash). Example: docker.m.daocloud.io/library/
ARG REGISTRY_PREFIX=
FROM ${REGISTRY_PREFIX}node:20-alpine AS build

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci

COPY . .

ARG VITE_API_BASE_PATH=/api/v1
ARG VITE_BACKEND_ORIGIN=http://backend:8000

ENV VITE_API_BASE_PATH=${VITE_API_BASE_PATH}
ENV VITE_BACKEND_ORIGIN=${VITE_BACKEND_ORIGIN}

RUN npm run build

FROM ${REGISTRY_PREFIX}node:20-alpine AS dev

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci

COPY . .

EXPOSE 5173

CMD ["npm", "run", "dev", "--", "--host=0.0.0.0", "--port=5173"]

FROM ${REGISTRY_PREFIX}nginx:1.27-alpine AS production

COPY docker/nginx.conf /etc/nginx/conf.d/default.conf
COPY --from=build /app/dist /usr/share/nginx/html

EXPOSE 80

CMD ["nginx", "-g", "daemon off;"]
