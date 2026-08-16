<?php

declare(strict_types=1);

namespace Gurb\Tests;

use Gurb\GurbApiException;
use Gurb\GurbClient;
use Gurb\Model\Task;
use Gurb\Tests\Support\StubHttpClient;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The task board — the wire contract.
 *
 * WHY THESE ASSERTIONS AND NOT "does it work"
 *
 * Every method here is a PUBLISHED CONTRACT. Once a customer's integration calls
 * it, changing a path or a verb breaks their production silently, at a moment we
 * do not choose and cannot roll back for them. Pinning the verb and the URL in a
 * test means such a change fails here instead of there.
 *
 * These assertions are deliberately IDENTICAL to the ones in the TypeScript
 * SDK's tasks suite. The two libraries describe ONE contract, and the only thing
 * that stops them drifting apart is that both are pinned to the same claims. If
 * you change one file, change the other in the same commit.
 */
final class TasksTest extends TestCase
{
    private const KEY = 'gurb_a1b2c3d4e5f6_' . 'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx';

    private function clientReturning(array $data, ?StubHttpClient &$http = null): GurbClient
    {
        $http = StubHttpClient::json(200, ['success' => true, 'data' => $data]);

        return new GurbClient(self::KEY, 'https://x.test', $http);
    }

    // ─── Verb and path ───────────────────────────────────────────────────────

    #[Test]
    public function tasks_are_listed_from_the_tasks_path(): void
    {
        $client = $this->clientReturning(['items' => [$this->taskRow()], 'total' => 1], $http);
        $page = $client->tasks->list();

        self::assertSame('GET', $http->lastCall()->method);
        self::assertSame('https://x.test/api/sdk/tasks', $http->lastCall()->url);
        self::assertInstanceOf(Task::class, $page->items[0]);
    }

    #[Test]
    public function one_task_is_fetched_by_id(): void
    {
        $client = $this->clientReturning($this->taskRow(), $http);
        $client->tasks->get('tsk_1');

        self::assertSame('GET', $http->lastCall()->method);
        self::assertSame('https://x.test/api/sdk/tasks/tsk_1', $http->lastCall()->url);
    }

    #[Test]
    public function creating_a_task_is_a_post_to_the_tasks_path(): void
    {
        $client = $this->clientReturning($this->taskRow(), $http);
        $client->tasks->create('hd_1', 'مراجعة الطلبات');

        self::assertSame('POST', $http->lastCall()->method);
        self::assertSame('https://x.test/api/sdk/tasks', $http->lastCall()->url);
    }

    #[Test]
    public function update_is_a_patch_and_delete_is_a_delete(): void
    {
        // PATCH and not PUT: the platform builds its SET list from the fields
        // present, so the update is partial in fact, and PATCH is what this
        // surface uses everywhere. A library sending PUT would look correct and
        // quietly imply a full replacement.
        $client = $this->clientReturning($this->taskRow(), $http);

        $client->tasks->update('tsk_1', ['status' => 'COMPLETED']);
        self::assertSame('PATCH', $http->lastCall()->method);
        self::assertSame('https://x.test/api/sdk/tasks/tsk_1', $http->lastCall()->url);

        $client->tasks->delete('tsk_1');
        self::assertSame('DELETE', $http->lastCall()->method);
        self::assertSame('https://x.test/api/sdk/tasks/tsk_1', $http->lastCall()->url);
    }

    // ─── Ids are caller input ────────────────────────────────────────────────

    #[Test]
    public function an_id_containing_a_slash_cannot_walk_onto_another_route(): void
    {
        // Unencoded, "a/b" turns DELETE sdk/tasks/a/b into a path this SDK never
        // meant to call — which on this backend answers 400 "Tenant context
        // required" rather than 404, sending the integrator to audit their
        // credentials over a bad id.
        $client = $this->clientReturning([], $http);

        $client->tasks->delete('a/b');
        self::assertSame('https://x.test/api/sdk/tasks/a%2Fb', $http->lastCall()->url);

        $client->tasks->get('a/b');
        self::assertSame('https://x.test/api/sdk/tasks/a%2Fb', $http->lastCall()->url);
    }

    // ─── Filters ─────────────────────────────────────────────────────────────

    #[Test]
    public function an_unused_filter_never_reaches_the_query_string(): void
    {
        // An empty `?status=` is a filter matching nothing, which returns an
        // empty page that looks exactly like "this community has no tasks".
        $client = $this->clientReturning(['items' => [], 'total' => 0], $http);
        $client->tasks->list();

        self::assertSame('https://x.test/api/sdk/tasks', $http->lastCall()->url);
    }

    #[Test]
    public function repeated_filter_values_are_sent_comma_joined(): void
    {
        $client = $this->clientReturning(['items' => [], 'total' => 0], $http);
        $client->tasks->list(status: ['PENDING', 'OVERDUE'], assigneeUserId: 'usr_1');

        self::assertSame(
            'https://x.test/api/sdk/tasks?status=PENDING%2COVERDUE&assigneeUserId=usr_1',
            $http->lastCall()->url,
        );
    }

    // ─── Optional fields ─────────────────────────────────────────────────────

    #[Test]
    public function an_omitted_optional_field_is_absent_from_the_body_entirely(): void
    {
        // Absence must mean absence: an absent `dueAt` says "no due date was
        // discussed", while `dueAt: null` is the instruction to CLEAR one.
        $client = $this->clientReturning($this->taskRow(), $http);
        $client->tasks->create('hd_1', 'مراجعة الطلبات');

        $body = \json_decode((string) $http->lastCall()->body, true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame(['headingId', 'title'], \array_keys($body));
    }

    #[Test]
    public function the_trimmed_values_are_what_get_sent(): void
    {
        // Validating a trimmed value and transmitting the untrimmed one is an
        // easy, real bug — and `title` is capped at 40 characters upstream, so
        // stray whitespace can be the difference between 200 and 400.
        $client = $this->clientReturning($this->taskRow(), $http);
        $client->tasks->create('  hd_1  ', '  مراجعة  ');

        $body = \json_decode((string) $http->lastCall()->body, true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame('hd_1', $body['headingId']);
        self::assertSame('مراجعة', $body['title']);
    }

    // ─── Local refusals ──────────────────────────────────────────────────────

    #[Test]
    public function a_task_without_a_heading_is_refused_before_any_request(): void
    {
        // There is no inbox to fall back on — tasks are section → heading → task
        // and the heading is required. Inventing one would create a container
        // the community never asked for and cannot find in its own UI.
        $http = StubHttpClient::json(200, ['success' => true, 'data' => []]);
        $client = new GurbClient(self::KEY, 'https://x.test', $http);

        try {
            $client->tasks->create('   ', 'مراجعة');
            self::fail('expected a local refusal');
        } catch (GurbApiException $e) {
            self::assertStringContainsString('headingId', $e->getMessage());
            self::assertCount(0, $http->calls, 'no rate-limit budget on an invalid draft');
        }
    }

    #[Test]
    public function a_task_without_a_title_is_refused_before_any_request(): void
    {
        $http = StubHttpClient::json(200, ['success' => true, 'data' => []]);
        $client = new GurbClient(self::KEY, 'https://x.test', $http);

        try {
            $client->tasks->create('hd_1', '  ');
            self::fail('expected a local refusal');
        } catch (GurbApiException $e) {
            self::assertSame(0, $e->status());
            self::assertCount(0, $http->calls);
        }
    }

    // ─── Decoding ────────────────────────────────────────────────────────────

    #[Test]
    public function a_list_row_reports_no_dependencies_without_claiming_there_are_none(): void
    {
        // `dependsOn` is empty on every list row because the platform does not
        // join it for a list. That is a property of the endpoint, not of the
        // task, and the model's docblock says so — this pins the decoding half.
        $client = $this->clientReturning(['items' => [$this->taskRow()], 'total' => 1], $http);
        $task = $client->tasks->list()->items[0];

        self::assertSame([], $task->dependsOn);
        self::assertNull($task->sectionId);
        // Absent `isActive` reads as TRUE, matching the server's own
        // `isActive !== false`.
        self::assertTrue($task->isActive);
        // A count, never a list: the platform's task detail returns an empty
        // assignments array by design.
        self::assertSame(0, $task->assignmentCount);
    }

    private function taskRow(): array
    {
        return [
            'id' => 'tsk_1',
            'headingId' => 'hd_1',
            'title' => 'مراجعة الطلبات',
            'status' => 'PENDING',
            'priority' => 'MEDIUM',
            'createdAt' => '2026-01-01T00:00:00.000Z',
        ];
    }
}
