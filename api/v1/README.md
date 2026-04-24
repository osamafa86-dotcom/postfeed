# Postfeed — Mobile API v1

Native-app API surface for iOS/Android. All endpoints return JSON and use
`Authorization: Bearer <token>` instead of session cookies.

## Base URL
```
https://feedsnews.net/api/v1
```

## Headers
| Header | Required | Notes |
|--------|----------|-------|
| `Authorization: Bearer <token>` | For user endpoints | Returned by `/auth/login` |
| `Content-Type: application/json` | On `POST` | |
| `X-App-Version` | recommended | e.g. `1.0.0 (12)` |
| `X-Platform` | recommended | `ios` or `android` |

## Endpoints

### Auth
| Method | Path | Auth | Notes |
|--------|------|------|-------|
| POST | `/auth/register` | — | `{ name, email, password, username? }` → `{ token, user }` |
| POST | `/auth/login` | — | `{ email, password, device_name?, app_version? }` → `{ token, user }` |
| POST | `/auth/logout` | ✅ | Revokes current token |
| POST | `/auth/delete_account` | ✅ | `{ password }` — **required by App Store 5.1.1(v)** |

### Content (public)
| Method | Path | Notes |
|--------|------|-------|
| GET | `/articles` | `?category=&source=&breaking=&q=&limit=&before_id=` (cursor pagination) |
| GET | `/article?id=` | Full content + related + user state |
| GET | `/search?q=` | |
| GET | `/trending?limit=` | |
| GET | `/categories` | Includes `following` flag when authed |
| GET | `/sources` | Includes `following` flag when authed |
| GET | `/ask?q=` | AI Q&A (15/hr limit) |
| GET | `/comments?article_id=` | |

### User (authenticated)
| Method | Path | Notes |
|--------|------|-------|
| GET\|POST | `/user/me` | Fetch or update profile |
| GET\|POST\|DELETE | `/user/bookmarks` | Toggle with `POST { article_id }` |
| POST | `/user/follow` | `{ kind: "category"|"source", id, action }` |
| POST | `/reactions` | `{ article_id, reaction: "like"|"dislike"|null }` |
| POST\|DELETE | `/comments` | |
| GET\|POST | `/notifications` | |
| POST | `/report` | `{ kind, target_id, reason }` — **required by App Store 1.2** |

### Devices (push)
| Method | Path | Notes |
|--------|------|-------|
| POST | `/devices/register` | `{ push_token, platform, bundle_id, ... }` |
| DELETE | `/devices/register` | `?push_token=...` |

## Errors
```json
{ "ok": false, "error": "code", "message": "human-readable Arabic" }
```
Common codes: `auth_required` (401), `invalid_credentials` (401), `not_found` (404),
`invalid_input` (400), `rate_limited` (429), `server_error` (500).

## Rate limits (per user if authed, else per IP)
- Auth login: 10/min · Register: 5/10min
- Articles list: 240/min · Article get: 240/min
- Search: 30/min · Trending: 120/min · Ask: 15/hour
- Bookmarks toggle: 60/min · Reactions: 120/min
- Comments add: 15/5min · Report: 30/5min · Notifications: 120/min
