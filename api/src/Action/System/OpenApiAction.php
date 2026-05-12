<?php

declare(strict_types=1);

namespace MyInvoice\Action\System;

use MyInvoice\Bootstrap;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Public servírování OpenAPI specifikace a Redoc dokumentační stránky.
 *
 *   GET /api/openapi.yaml  → ručně psaný spec v api/openapi.yaml
 *   GET /api/docs          → Redoc HTML (CDN inclusion, žádný build step)
 */
final class OpenApiAction
{
    public function reference(Request $request, Response $response): Response
    {
        // Redoc varianta — staticky vypadající dokumentace, větší typografie,
        // code samples vlevo / popis vpravo. Žádné „Try it out" — to je v /api/docs.
        $html = <<<'HTML'
<!doctype html>
<html lang="cs">
<head>
  <meta charset="utf-8">
  <title>MyInvoice.cz API — Reference</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="icon" type="image/svg+xml" href="/favicon.svg">
  <style>
    html { font-size: 16px; }
    body { margin: 0; padding: 0; }
    .topbar-mi {
      display: flex; align-items: center; gap: 14px;
      padding: 14px 28px;
      background: #15131D; color: #fff;
      border-bottom: 3px solid #3B2D83;
      height: 60px; box-sizing: border-box;
      position: sticky; top: 0; z-index: 100;
      font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", system-ui, sans-serif;
    }
    /* Redoc sidebar = sticky overlay zarovnaný k viewportu. Bez tohoto se
       překryje s naší topbar — posuneme jeho `top` o 60px a redukujeme height. */
    redoc .menu-content {
      top: 60px !important;
      height: calc(100vh - 60px) !important;
    }
    .topbar-mi a.brand { color: #fff; text-decoration: none; font-weight: 600; font-size: 17px; }
    .topbar-mi a.brand:hover { color: #C5BDFF; }
    .topbar-mi .spacer { flex: 1; }
    .topbar-mi a.link {
      color: #C5BDFF; text-decoration: none; font-size: 14px;
      padding: 7px 12px; border-radius: 6px;
    }
    .topbar-mi a.link:hover { background: #3B2D83; color: #fff; }
    .topbar-mi .badge {
      background: #3B2D83; color: #fff; font-size: 12px;
      padding: 4px 10px; border-radius: 4px; font-weight: 600;
      letter-spacing: 0.6px; text-transform: uppercase;
    }
    /* Redoc overrides — větší písmo, čitelnější rozestupy */
    redoc { display: block; min-height: calc(100vh - 60px); }
  </style>
</head>
<body>
  <div class="topbar-mi">
    <a class="brand" href="/">← MyInvoice.cz</a>
    <span class="badge">REST API v1 · Reference</span>
    <span class="spacer"></span>
    <a class="link" href="/api/docs">⚡ Try it out (Swagger UI)</a>
    <a class="link" href="/api/openapi.yaml" target="_blank">📄 openapi.yaml</a>
    <a class="link" href="/manual/?ch=20" target="_blank">📖 Manuál</a>
  </div>
  <div id="redoc-container"></div>
  <script src="https://cdn.redoc.ly/redoc/latest/bundles/redoc.standalone.js"></script>
  <script>
    Redoc.init('/api/openapi.yaml', {
      scrollYOffset: 60,             // posune anchor-jumps pod fixní topbar
      hideDownloadButton: false,
      expandResponses: '200,201',
      expandSingleSchemaField: true,
      jsonSampleExpandLevel: 4,
      requiredPropsFirst: true,
      pathInMiddlePanel: true,
      nativeScrollbars: false,
      theme: {
        spacing: {
          unit: 6,
          sectionHorizontal: 48,
          sectionVertical: 32
        },
        breakpoints: {
          small: '50rem',
          medium: '78rem',
          large: '105rem'
        },
        colors: {
          primary: { main: '#3B2D83' },
          success: { main: '#21A86A' },
          warning: { main: '#D49C2E' },
          error:   { main: '#D45B5B' },
          text:    { primary: '#15131D', secondary: '#3F3A52' },
          border:  { dark: '#15131D', light: '#E5E1F0' },
          http: {
            get:    '#21A86A',
            post:   '#3B2D83',
            put:    '#D49C2E',
            delete: '#D45B5B',
            patch:  '#7E6DD6'
          }
        },
        typography: {
          fontSize: '16px',
          lineHeight: '1.6em',
          fontFamily: '-apple-system, BlinkMacSystemFont, "Segoe UI", system-ui, sans-serif',
          smoothing: 'antialiased',
          optimizeSpeed: false,
          headings: {
            fontFamily: '-apple-system, BlinkMacSystemFont, "Segoe UI", system-ui, sans-serif',
            fontWeight: '700',
            lineHeight: '1.4em'
          },
          code: {
            fontFamily: '"JetBrains Mono", "Fira Code", Consolas, monospace',
            fontSize: '14px',
            lineHeight: '1.55em',
            color:    '#15131D',
            backgroundColor: '#F4F2F8',
            wrap: true
          },
          links: { color: '#3B2D83', visited: '#3B2D83', hover: '#7E6DD6' }
        },
        sidebar: {
          backgroundColor: '#FAFAFC',
          textColor: '#15131D',
          width: '320px'
        },
        rightPanel: {
          backgroundColor: '#15131D',
          textColor: '#F4F2F8',
          width: '42%'
        },
        codeBlock: {
          backgroundColor: '#0F0E1A'
        },
        logo: {
          gutter: '24px'
        }
      }
    }, document.getElementById('redoc-container'));
  </script>
</body>
</html>
HTML;
        $response->getBody()->write($html);
        return $response
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withHeader('Cache-Control', 'public, max-age=300');
    }

    public function spec(Request $request, Response $response): Response
    {
        $path = Bootstrap::rootDir() . '/api/openapi.yaml';
        $body = @file_get_contents($path);
        if ($body === false) {
            $response->getBody()->write('# openapi.yaml not deployed');
            return $response->withStatus(500)->withHeader('Content-Type', 'text/plain; charset=utf-8');
        }
        $response->getBody()->write($body);
        return $response
            ->withHeader('Content-Type', 'application/yaml; charset=utf-8')
            ->withHeader('Cache-Control', 'public, max-age=300')
            ->withHeader('Access-Control-Allow-Origin', '*'); // Postman/Insomnia import
    }

    public function docs(Request $request, Response $response): Response
    {
        // Swagger UI 5.x z unpkg CDN — interaktivní „Try it out" pro endpointy
        // s BearerAuth integrací. Theming přes CSS overrides sladěný s MyInvoice
        // brandem (primary #3B2D83, dark text #15131D).
        $html = <<<'HTML'
<!doctype html>
<html lang="cs">
<head>
  <meta charset="utf-8">
  <title>MyInvoice.cz API — Dokumentace</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="icon" type="image/svg+xml" href="/favicon.svg">
  <link rel="stylesheet" href="https://unpkg.com/swagger-ui-dist@5/swagger-ui.css">
  <style>
    body { margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", system-ui, sans-serif; background: #F4F2F8; }
    .topbar-mi {
      display: flex; align-items: center; gap: 12px;
      padding: 12px 24px;
      background: #15131D; color: #fff;
      border-bottom: 3px solid #3B2D83;
      position: sticky; top: 0; z-index: 100;
    }
    .topbar-mi a.brand { color: #fff; text-decoration: none; font-weight: 600; font-size: 16px; }
    .topbar-mi a.brand:hover { color: #C5BDFF; }
    .topbar-mi .spacer { flex: 1; }
    .topbar-mi a.link {
      color: #C5BDFF; text-decoration: none; font-size: 13px;
      padding: 6px 10px; border-radius: 6px;
    }
    .topbar-mi a.link:hover { background: #3B2D83; color: #fff; }
    .topbar-mi .badge {
      background: #3B2D83; color: #fff; font-size: 11px;
      padding: 3px 8px; border-radius: 4px; font-weight: 600;
      letter-spacing: 0.5px; text-transform: uppercase;
    }
    /* Swagger UI vlastní topbar skryjeme — máme svůj brand */
    .swagger-ui .topbar { display: none; }
    /* Brand colors: primární purple #3B2D83 */
    .swagger-ui .info .title { color: #15131D; }
    .swagger-ui .scheme-container { background: #FAFAFC; box-shadow: 0 1px 2px rgba(0,0,0,0.05); }
    .swagger-ui .btn.authorize { background: #3B2D83; color: #fff; border-color: #3B2D83; }
    .swagger-ui .btn.authorize:hover { background: #2A1F66; border-color: #2A1F66; }
    .swagger-ui .btn.authorize svg { fill: #fff; }
    .swagger-ui .btn.execute { background: #3B2D83; color: #fff; border-color: #3B2D83; }
    .swagger-ui .btn.execute:hover { background: #2A1F66; }
    .swagger-ui .opblock-tag { color: #15131D; border-bottom: 1px solid #E5E1F0; }
    .swagger-ui .opblock.opblock-get  .opblock-summary-method { background: #21A86A; }
    .swagger-ui .opblock.opblock-post .opblock-summary-method { background: #3B2D83; }
    .swagger-ui .opblock.opblock-put  .opblock-summary-method { background: #D49C2E; }
    .swagger-ui .opblock.opblock-delete .opblock-summary-method { background: #D45B5B; }
    .swagger-ui .opblock.opblock-get  { background: rgba(33,168,106,0.05); border-color: rgba(33,168,106,0.3); }
    .swagger-ui .opblock.opblock-post { background: rgba(59,45,131,0.05); border-color: rgba(59,45,131,0.3); }
    .swagger-ui .opblock.opblock-put  { background: rgba(212,156,46,0.05); border-color: rgba(212,156,46,0.3); }
    .swagger-ui .opblock.opblock-delete { background: rgba(212,91,91,0.05); border-color: rgba(212,91,91,0.3); }
    /* Body inset pro lepší vzhled */
    #swagger-ui { max-width: 1280px; margin: 0 auto; padding: 0 16px; }
  </style>
</head>
<body>
  <div class="topbar-mi">
    <a class="brand" href="/">← MyInvoice.cz</a>
    <span class="badge">REST API v1</span>
    <span class="spacer"></span>
    <a class="link" href="/api/reference">📚 Reference (Redoc)</a>
    <a class="link" href="/api/openapi.yaml" target="_blank">📄 openapi.yaml</a>
    <a class="link" href="/manual/?ch=20" target="_blank">📖 Manuál</a>
    <a class="link" href="https://github.com/radekhulan/myinvoice" target="_blank">⭐ GitHub</a>
  </div>
  <div id="swagger-ui"></div>
  <script src="https://unpkg.com/swagger-ui-dist@5/swagger-ui-bundle.js"></script>
  <script src="https://unpkg.com/swagger-ui-dist@5/swagger-ui-standalone-preset.js"></script>
  <script>
    window.ui = SwaggerUIBundle({
      url: '/api/openapi.yaml',
      dom_id: '#swagger-ui',
      deepLinking: true,
      docExpansion: 'list',          // tagy rozbalené, endpointy sbalené
      defaultModelsExpandDepth: 1,
      defaultModelExpandDepth: 2,
      displayRequestDuration: true,
      filter: true,                  // hledací box nad seznamem
      tryItOutEnabled: true,         // "Try it out" defaultně k dispozici
      persistAuthorization: true,    // token zůstane mezi reloady (localStorage)
      requestSnippetsEnabled: true,
      syntaxHighlight: { activate: true, theme: 'agate' },
      presets: [
        SwaggerUIBundle.presets.apis,
        SwaggerUIStandalonePreset.slice(1)  // bez topbaru, máme vlastní
      ],
      plugins: [SwaggerUIBundle.plugins.DownloadUrl],
      layout: 'BaseLayout'
    });
  </script>
</body>
</html>
HTML;
        $response->getBody()->write($html);
        return $response
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withHeader('Cache-Control', 'public, max-age=300');
    }
}
