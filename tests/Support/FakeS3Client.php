<?php

declare(strict_types=1);

// File: tests/Support/FakeS3Client.php

namespace Resumable\ChunkedUploader\Tests\Support;

use Aws\Command;
use Aws\Exception\AwsException;
use Aws\S3\S3Client;
use RuntimeException;

/**
 * Minimal AWS SDK client double that captures command calls and dispatches them
 * to per-command handlers. AWS SDK commands such as putObject/getObject are
 * routed through Client::__call(), so they cannot be stubbed with PHPUnit mocks.
 */
final class FakeS3Client extends S3Client
{
    /** @var list<array{string, list<mixed>}> */
    public array $calls = [];

    /** @var array<string, callable> */
    private array $handlers = [];

    /**
     * Paginated results are registered per operation: the orphan sweeper walks
     * both ListMultipartUploads and ListObjectsV2 in a single call, so a single
     * shared stub would feed one operation's payload to the other.
     *
     * @var array<string, callable>
     */
    private array $pageFactories = [];

    public function __construct()
    {
    }

    public function on(string $command, callable $handler): void
    {
        $this->handlers[$command] = $handler;
    }

    public function paginate(string $operation, callable $pageFactory): void
    {
        $this->pageFactories[$operation] = $pageFactory;
    }

    /**
     * Names of the commands issued, in order.
     *
     * @return list<string>
     */
    public function commandNames(): array
    {
        return array_map(static fn (array $call): string => $call[0], $this->calls);
    }

    /**
     * Arguments of every call to one command.
     *
     * @return list<array<string, mixed>>
     */
    public function argsFor(string $command): array
    {
        $found = [];
        foreach ($this->calls as $call) {
            if ($call[0] === $command) {
                $args = $call[1][0] ?? [];
                self::assertIsArray($args);
                $found[] = $args;
            }
        }

        return $found;
    }

    public function getPaginator($operation, $args = []): \Iterator
    {
        $pageFactory = $this->pageFactories[(string) $operation] ?? null;
        self::assertNotNull($pageFactory, 'No paginated stub registered for ' . $operation);

        $page = $pageFactory($args);
        if ($page instanceof \Iterator || $page instanceof \IteratorAggregate) {
            yield from $page;

            return;
        }

        yield $page;
    }

    public function __call($name, $arguments)
    {
        $this->calls[] = [$name, $arguments];

        $handler = $this->handlers[$name] ?? null;
        if ($handler !== null) {
            return $handler(...$arguments);
        }

        return [];
    }
}
