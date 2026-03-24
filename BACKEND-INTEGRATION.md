# UTEEK Log Tracker – Backend Integration Guide

This document is addressed to the **Node.js backend developer**.  
It describes exactly what the PHP SDK sends, what endpoints you must implement, how authentication works, and how you should forward the processed logs to the Laravel monitoring app.

---

## 1. Architecture Overview

```
PHP Project (any framework)
  └─ LogTrackerClient (this SDK)
       └─ POST /ingest  ──►  Node.js (next.js)
                               ├─ authenticate X-API-Key  ←── issued by Laravel dashboard
                               ├─ store raw logs in MongoDB
                               ├─ filter / deduplicate / enrich
                               └─ POST /api/logs  ──►  Laravel monitoring app
```

The SDK **never** talks to Laravel directly.  
Node.js is the single entry point for all PHP log ingestion.

---

## 2. Required Endpoints

### 2.1 `GET /ping`

Used by the SDK at initialisation time (and in CI/CD pipelines) to verify that the API key is valid before any real logs are sent.

**Request headers**

| Header          | Value                   |
|-----------------|-------------------------|
| `X-API-Key`     | The project's API key   |
| `X-SDK-Version` | e.g. `1.0.0`            |

**Responses**

| HTTP | Condition      | Body                        |
|------|----------------|-----------------------------|
| 200  | Key is valid   | `{ "status": "ok" }`        |
| 401  | Key not found  | `{ "error": "Unauthorized" }` |
| 403  | Key revoked    | `{ "error": "Forbidden" }`  |

---

### 2.2 `POST /ingest`

Receives a **JSON array** of log entries from the SDK.  
The SDK buffers logs and sends them in one batch (up to 50 entries by default).

**Request headers**

| Header            | Value                          |
|-------------------|--------------------------------|
| `Content-Type`    | `application/json`             |
| `X-API-Key`       | The project's API key          |
| `X-SDK-Version`   | e.g. `1.0.0`                   |

**Responses**

| HTTP | Condition                   | Body                          |
|------|-----------------------------|-------------------------------|
| 202  | Logs accepted               | `{ "accepted": <count> }`     |
| 400  | Invalid JSON / missing body | `{ "error": "Invalid payload" }` |
| 401  | Key not found               | `{ "error": "Unauthorized" }` |
| 403  | Key revoked / wrong project | `{ "error": "Forbidden" }`    |

> **Important:** Return `202` (not `200`) — the SDK does not wait for processing to finish.  
> If the SDK receives `401` or `403`, it **permanently disables itself** for the rest of the request lifecycle and logs a warning to the PHP error log.

---

## 3. Payload Structure

The request body is a **JSON array**. Each element is one log entry.

```json
[
  {
    "ts":          1741441921000,
    "date":        "2026-03-08",
    "datetime":    "2026-03-08T14:32:01+00:00",
    "level":       "ERROR",
    "message":     "Payment gateway timeout",
    "stack_trace": "RuntimeException: Payment gateway timeout\n  in /app/PaymentService.php:42\n  ...",
    "project_id":  "proj_abc123",
    "environment": "production",
    "framework":   "laravel",
    "app":         "php",
    "host":        "web-01",
    "sdk_version": "1.0.0",
    "context": {
      "exception_class": "RuntimeException",
      "file":            "/app/PaymentService.php",
      "line":            42,
      "code":            504,
      "order_id":        99,
      "request": {
        "method":     "POST",
        "url":        "https://shop.example.com/checkout",
        "ip":         "203.0.113.5",
        "user_agent": "Mozilla/5.0 ..."
      },
      "server": {
        "php_version": "8.2.15",
        "os":          "Linux",
        "sapi":        "fpm-fcgi"
      },
      "user": {
        "id":    7,
        "email": "customer@example.com"
      }
    }
  }
]
```

### Field Reference

| Field           | Type    | Always present | Description                                      |
|-----------------|---------|---------------|--------------------------------------------------|
| `ts`            | integer | ✓             | Unix timestamp in **milliseconds** (UTC)         |
| `date`          | string  | ✓             | `YYYY-MM-DD` (UTC) – useful for MongoDB date queries |
| `datetime`      | string  | ✓             | ISO 8601 full datetime (UTC)                     |
| `level`         | string  | ✓             | One of: `DEBUG` `INFO` `WARNING` `ERROR` `CRITICAL` |
| `message`       | string  | ✓             | Human-readable log message                       |
| `stack_trace`   | string  | only on exceptions | Full PHP stack trace                        |
| `project_id`    | string  | ✓             | Matches the project record in your DB            |
| `environment`   | string  | ✓             | e.g. `production`, `staging`, `development`      |
| `framework`     | string  | ✓             | `laravel` `wordpress` `symfony` `php`            |
| `app`           | string  | ✓             | Always `"php"` (reserved for future SDKs)        |
| `host`          | string  | ✓             | Server hostname                                  |
| `sdk_version`   | string  | ✓             | SDK version that sent the log                    |
| `context`       | object  | ✓             | Merged context: request, server, user, extras    |
| `context.request` | object | HTTP only  | Present only for web requests (not CLI)          |
| `context.server`  | object | ✓          | PHP version, OS, SAPI                            |
| `context.user`    | object | if set     | Set by the developer via `$client->setUser([])` |

---

## 4. Authentication

API keys are **issued by the Laravel monitoring dashboard** when a developer creates a project.

```
Laravel dashboard  ──►  generates key  ──►  stored in DB (hashed)
                                              ▲
                              Node.js validates against this DB
                              on every /ping and /ingest request
```

**Recommended Node.js validation flow:**

```js
// middleware/authenticate.js
const Project = require('../models/Project');

module.exports = async (req, res, next) => {
  const key = req.headers['x-api-key'];

  if (!key) {
    return res.status(401).json({ error: 'Unauthorized' });
  }

  // Look up the key in your database (store it hashed with bcrypt/argon2).
  const project = await Project.findOne({ apiKeyHash: hashKey(key) });
  if (!project) {
    return res.status(401).json({ error: 'Unauthorized' });
  }
  if (project.revoked) {
    return res.status(403).json({ error: 'Forbidden' });
  }

  req.project = project;
  next();
};
```

> **Security note:** Store the API key as a **one-way hash** (bcrypt / argon2) in your database — never plaintext. The dashboard shows the key once on creation; after that only the hash is kept.

---

## 5. MongoDB Schema Recommendation

```js
// models/Log.js
const logSchema = new Schema({
  // ── Identity ──────────────────────────────────────────────────
  projectId:   { type: String, required: true, index: true },
  environment: { type: String, required: true, index: true },
  framework:   { type: String },

  // ── Log data ──────────────────────────────────────────────────
  level:       { type: String, required: true, index: true },
  message:     { type: String, required: true },
  stackTrace:  { type: String },

  // ── Timestamps ────────────────────────────────────────────────
  ts:          { type: Number, required: true },          // ms timestamp
  date:        { type: String, required: true, index: true }, // YYYY-MM-DD
  datetime:    { type: Date,   required: true },

  // ── Context ───────────────────────────────────────────────────
  context:     { type: Schema.Types.Mixed },

  // ── Metadata ──────────────────────────────────────────────────
  host:        { type: String },
  sdkVersion:  { type: String },
  app:         { type: String, default: 'php' },

  receivedAt:  { type: Date, default: Date.now },
}, {
  collection: 'logs',
});

// Compound index for the most common dashboard query
logSchema.index({ projectId: 1, date: -1, level: 1 });
// TTL: auto-delete logs older than 90 days
logSchema.index({ receivedAt: 1 }, { expireAfterSeconds: 90 * 24 * 60 * 60 });
```

---

## 6. Forwarding Logs to the Laravel App

After storing in MongoDB, Node.js should forward a summary/processed version to the Laravel monitoring API.

```js
// services/forward.js
const axios = require('axios');

async function forwardToLaravel(logs, project) {
  if (!logs.length) return;

  await axios.post(
    `${process.env.LARAVEL_API_URL}/api/ingest`,
    {
      project_id:  project._id,
      logs:        logs.map(normalise),
    },
    {
      headers: {
        'Authorization': `Bearer ${process.env.LARAVEL_INTERNAL_TOKEN}`,
        'Content-Type':  'application/json',
      },
      timeout: 5000,
    }
  );
}

function normalise(log) {
  return {
    level:      log.level,
    message:    log.message,
    datetime:   log.datetime,
    date:       log.date,
    framework:  log.framework,
    environment:log.environment,
    stack_trace:log.stackTrace ?? null,
    context:    log.context ?? {},
  };
}
```

> Use a **queue** (BullMQ / Redis) instead of awaiting `forwardToLaravel()` inline so that a slow Laravel response never delays the `202` sent back to the PHP SDK.

---

## 7. Express Route Example

```js
// routes/ingest.js
const express  = require('express');
const router   = express.Router();
const auth     = require('../middleware/authenticate');
const Log      = require('../models/Log');
const { forwardToLaravel } = require('../services/forward');

router.get('/ping', auth, (req, res) => {
  res.status(200).json({ status: 'ok' });
});

router.post('/ingest', auth, async (req, res) => {
  const raw = req.body;

  if (!Array.isArray(raw) || raw.length === 0) {
    return res.status(400).json({ error: 'Invalid payload' });
  }

  // Enforce project_id matches the authenticated project
  const logs = raw.map(entry => ({
    ...entry,
    projectId:  req.project._id.toString(),
    datetime:   new Date(entry.datetime),
    receivedAt: new Date(),
  }));

  await Log.insertMany(logs, { ordered: false }); // ordered:false = partial success ok

  // Non-blocking forward to Laravel
  forwardToLaravel(logs, req.project).catch(err =>
    console.error('Forward to Laravel failed:', err.message)
  );

  res.status(202).json({ accepted: logs.length });
});

module.exports = router;
```

---

## 8. Environment Variables (Node.js)

```env
PORT=3000

# MongoDB
MONGO_URI=mongodb://localhost:27017/uteek_logs

# Laravel monitoring app
LARAVEL_API_URL=https://monitoring.uteek.net
LARAVEL_INTERNAL_TOKEN=your-shared-secret-token

# Optional: for key hashing
API_KEY_PEPPER=a-random-secret-string
```

---

## 9. Testing with the Mock Server

A ready-made mock server is included in the SDK repository for local development:

```bash
# Terminal 1 – start mock server
php -S localhost:3000 tests/mock-server.php

# Terminal 2 – run SDK test suite
php tests/run-test.php
```

Received logs are saved to `tests/received-logs.json` so you can inspect the exact JSON your Node.js server will receive.

---

## 10. Quick Checklist

- [ ] `GET /ping` — validates `X-API-Key`, returns `200` or `401/403`
- [ ] `POST /ingest` — validates key, accepts JSON array, returns `202`
- [ ] API key stored **hashed** in DB, never plaintext
- [ ] `401`/`403` responses cause the SDK to self-disable (no need to handle re-auth)
- [ ] MongoDB compound index on `{ projectId, date, level }`
- [ ] TTL index to auto-expire old logs
- [ ] Forward to Laravel via queue (BullMQ recommended)
- [ ] `LARAVEL_INTERNAL_TOKEN` kept out of version control
