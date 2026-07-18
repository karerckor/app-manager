import http from 'node:http';

const host = '127.0.0.1';
const port = Number.parseInt(process.env.APP_MANAGER_DEMO_PORT ?? '4567', 10);
const apiKey = process.env.APP_MANAGER_API_KEY ?? 'demo-fastlane-api-key';
const team = process.env.APP_MANAGER_TEAM ?? 'demo-users-team';
const app = process.env.APP_MANAGER_APP ?? 'example-app';
const apiRoot = `/api/v1/${team}/apps/${app}`;

const appResponse = {
  data: {
    id: 'demo-app-1',
    slug: app,
    name: 'Expo Hello World',
    description: 'A self-contained CI demo for fastlane-plugin-app_manager.',
    assets: {
      icon: { name: 'app-manager-demo-icon.svg' },
    },
    created_at: '2026-07-18T00:00:00Z',
    updated_at: '2026-07-18T00:00:00Z',
  },
};

const identifiersResponse = {
  data: [
    {
      id: 'demo-android-identifier',
      platform: 'android',
      identifier: 'com.appmanager.demo.android',
      name: 'Android demo',
      version: '1.0.0',
      version_code: 10000,
    },
    {
      id: 'demo-ios-identifier',
      platform: 'ios',
      identifier: 'com.appmanager.demo.ios',
      name: 'iOS demo',
      version: '1.0.0',
      version_code: '1',
    },
  ],
};

const icon = `<?xml version="1.0" encoding="UTF-8"?>
<svg xmlns="http://www.w3.org/2000/svg" width="128" height="128" viewBox="0 0 128 128">
  <rect width="128" height="128" rx="28" fill="#5b45ff"/>
  <path d="M33 39h62v12H33zm0 19h62v12H33zm0 19h38v12H33z" fill="white"/>
</svg>
`;

function sendJson(response, status, body) {
  response.writeHead(status, { 'content-type': 'application/json' });
  response.end(JSON.stringify(body));
}

const server = http.createServer((request, response) => {
  console.log(`[mock-app-manager] ${request.method} ${request.url}`);

  if (request.url === '/health') {
    return sendJson(response, 200, { status: 'ok' });
  }

  if (request.headers.authorization !== `Bearer ${apiKey}`) {
    return sendJson(response, 401, { message: 'Invalid demo API key.' });
  }

  if (request.method === 'GET' && request.url === apiRoot) {
    return sendJson(response, 200, appResponse);
  }

  if (request.method === 'GET' && request.url === `${apiRoot}/identifiers`) {
    return sendJson(response, 200, identifiersResponse);
  }

  if (request.method === 'GET' && request.url === `${apiRoot}/assets/icon`) {
    response.writeHead(200, { 'content-type': 'image/svg+xml' });
    return response.end(icon);
  }

  return sendJson(response, 404, { message: 'Demo endpoint not found.' });
});

server.listen(port, host, () => {
  console.log(`[mock-app-manager] listening on http://${host}:${port}`);
});

function shutdown() {
  server.close(() => process.exit(0));
}

process.on('SIGINT', shutdown);
process.on('SIGTERM', shutdown);
