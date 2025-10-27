FROM node:20-alpine

WORKDIR /app

# 必要ツール: git（依存取得用）/ python3 + make + g++（node-gyp用）
RUN apk add --no-cache git python3 make g++

# lockfile があれば ci、なければ install
COPY package.json package-lock.json* ./
RUN if [ -f package-lock.json ]; then \
      npm ci --omit=dev; \
    else \
      npm install --omit=dev; \
    fi

COPY server.js ./

ENV NODE_ENV=production
EXPOSE 3001
CMD ["node", "server.js"]
