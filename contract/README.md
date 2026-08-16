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

**Start a fresh mock for each run.** It keeps its state in memory and this harness asserts exact
counts and exact role transitions — a second run against the same process removes a member that is
already gone. That is a property of the fixture, not a flaky test: restart the mock, or expect the
first `DELETE sdk/members/mem_3` to 404.

Exit code is 0 when every check passes, 1 otherwise, so it drops into CI as-is once the real
endpoints ship — point `MOCK_BASE_URL` at a staging origin and the same assertions become a
smoke test of the backend.

## What it covers

Every path in the contract, community and admin:

| | |
|---|---|
| Community | `GET sdk/community`, `POST sdk/community-requests`, `GET sdk/community-requests`, `GET sdk/community-requests/{id}` |
| Content | `GET sdk/tweets`, `GET sdk/events`, `GET sdk/blogs`, `GET sdk/albums`, `GET sdk/groups`, `GET sdk/consultants`, `GET sdk/projects`, `GET sdk/projects2`, `GET sdk/awards` — each also re-issued with `?page=1&limit=1` to prove pagination behaves identically across all nine |
| Members | `GET sdk/members`, `GET sdk/members/{id}`, `PATCH sdk/members/{id}/role`, `GET sdk/members/{id}/permissions`, `PATCH sdk/members/{id}/permissions`, `DELETE sdk/members/{id}`, `POST sdk/members/bulk` |
| Embed | `POST embed/sessions`, and a real `GET /embed/{slug}/{section}` to prove the fragment never reached the server |
| Admin | `GET/POST admin/sdk/communities`, `GET admin/sdk/community-requests`, `GET admin/sdk/community-requests/{id}`, `POST .../approve`, `POST .../reject`, `GET/POST admin/sdk/api-keys`, `POST admin/sdk/api-keys/{id}/revoke` |

Plus the rules that are not endpoints: a community key refused on an admin route and vice versa,
a malformed key, an unknown route, the plan-gated `403`, the double-approve `400`, and every local
guard — each asserted to produce `VALIDATION_ERROR` at status `0` **and** to have opened no socket.

Two contract facts get their own assertions because they read as bugs otherwise: a **private group
is returned rather than filtered away**, and `projects` and `projects2` return **different rows**,
which is what proves the second is a parallel module and not an alias.

### Pending a real server

`POST admin/sdk/communities` is asserted end to end — the two-field body, the `201`, the decoded
`Community`, `PRIVATE` visibility, and every local name/slug guard firing with zero sockets opened.
Two things it **cannot** prove, because the mock predates the narrowing:

- that the server **ignores** `type` and `ownerUserId` if something sends them. The mock still
  reads `body.type ?? 'PRIVATE'`, so we observe `PRIVATE` because the SDK sends no `type`, not
  because the server refuses one.
- that the owner is taken **from the credential**. The mock does not model ownership at all.

Both need re-checking against the real endpoint when it ships. Everything else about this call is
covered here.

The plan gate is probed directly rather than through a resource method. The mock now gates blogs
behind `?gated=1` — blogs is a real listable section and a permanently-403 endpoint could not be
one — and no SDK method can send that flag, correctly, because it is not part of the contract. So
the harness issues the request itself and feeds the response through `GurbApiException::fromResponse()`,
which is the part that actually belongs to the SDK.
