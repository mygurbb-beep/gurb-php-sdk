# Wire-contract check

The unit suite (`tests/`) injects a stub transport, so it proves the SDK *builds* the right request.
It cannot prove that request is one a server would accept. This directory does the other half: it
drives the SDK over **real HTTP** against the TypeScript SDK's reference mock and asserts the verb,
the full URL, the headers, the exact request-body bytes, the HTTP status and the decoded model for
every endpoint the SDK can issue.

That matters because `gurb/sdk` and `@gurb/server` are two implementations of one route contract.
The two cannot be allowed to drift, and a mismatch in a path or a field name is invisible to a unit
test that asserts against its own expectations.

## Running it

The mock lives in the TypeScript SDK repository and is **not** copied here — one contract, one copy,
so there is nothing to keep in sync:

```bash
# 1. Terminal one — the reference server, from the TypeScript SDK checkout
node examples/host-demo/mock-gurb.mjs        # → http://localhost:5099

# 2. Terminal two — this SDK against it
MOCK_BASE_URL=http://localhost:5099 php contract/run.php
```

No PHP locally? Point the container at the host's mock:

```bash
docker run --rm --add-host=host.docker.internal:host-gateway \
  -v "$PWD":/sdk -w /sdk php:8.2-cli \
  php -d error_reporting=E_ALL contract/run.php
```

`MOCK_BASE_URL` defaults to `http://host.docker.internal:5099`, which is the container case.

Exit code is 0 when every check passes, 1 otherwise, so it drops into CI as-is once the real
endpoints ship — point `MOCK_BASE_URL` at a staging origin and the same assertions become a
smoke test of the backend.

## What it covers

Every path in the contract, community and admin:

| | |
|---|---|
| Community | `GET sdk/community`, `POST sdk/community-requests`, `GET sdk/community-requests`, `GET sdk/community-requests/{id}` |
| Content | `GET sdk/tweets`, `GET sdk/events`, `GET sdk/blogs`, `GET sdk/albums` |
| Members | `GET sdk/members`, `GET sdk/members/{id}`, `PATCH sdk/members/{id}/role`, `GET sdk/members/{id}/permissions`, `PATCH sdk/members/{id}/permissions`, `DELETE sdk/members/{id}`, `POST sdk/members/bulk` |
| Embed | `POST embed/sessions`, and a real `GET /embed/{slug}/{section}` to prove the fragment never reached the server |
| Admin | `GET/POST admin/sdk/communities`, `GET admin/sdk/community-requests`, `GET admin/sdk/community-requests/{id}`, `POST .../approve`, `POST .../reject`, `GET/POST admin/sdk/api-keys`, `POST admin/sdk/api-keys/{id}/revoke` |

Plus the rules that are not endpoints: a community key refused on an admin route and vice versa,
a malformed key, an unknown route, the plan-gated `403`, the double-approve `400`, and every local
guard — each asserted to produce `VALIDATION_ERROR` at status `0` **and** to have opened no socket.
