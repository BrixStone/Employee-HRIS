# HRIS real-time WebSocket server
FROM node:24-alpine

WORKDIR /srv/realtime
ENV NODE_ENV=production

# Install the exact versions from package-lock.json
COPY package.json package-lock.json ./
RUN npm ci --omit=dev && npm cache clean --force

COPY server.js ./

# Run as the unprivileged "node" user that ships with the image
USER node
EXPOSE 8090

HEALTHCHECK --interval=30s --timeout=5s --start-period=5s --retries=3 \
  CMD node -e "require('http').get('http://127.0.0.1:8090/health', r => process.exit(r.statusCode === 200 ? 0 : 1)).on('error', () => process.exit(1))"

CMD ["node", "server.js"]
