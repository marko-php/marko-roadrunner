<?php

declare(strict_types=1);

namespace Marko\Roadrunner\Tests\Worker;

use Marko\Roadrunner\Http\Psr7RequestBridge;
use Marko\Roadrunner\Http\Psr7ResponseBridge;
use Marko\Roadrunner\Worker\WorkerLogger;
use Marko\Roadrunner\Worker\WorkerRequestHandler;
use Marko\Routing\Http\Request;
use Marko\Routing\MatchedRoute;
use Marko\Routing\Router;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use Nyholm\Psr7\UploadedFile as Psr7UploadedFile;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;

describe('WorkerRequestHandler', function (): void {
    it('boots the application once for many requests', function (): void {
        $matcher = new FakeRouteMatcher();
        $router = new Router($matcher, new NullContainer());
        $psr7Worker = new FakePsr7Worker([
            new ServerRequest('GET', 'https://example.test/one'),
            new ServerRequest('GET', 'https://example.test/two'),
            new ServerRequest('GET', 'https://example.test/three'),
        ]);
        $handler = new WorkerRequestHandler(
            psr7Worker: $psr7Worker,
            router: $router,
            requestBridge: new Psr7RequestBridge(),
            responseBridge: new Psr7ResponseBridge(),
            logger: new WorkerLogger(),
            container: new NullContainer(),
        );

        $handler->run();

        expect($matcher->matchCount)->toBe(3)
            ->and($psr7Worker->responses)->toHaveCount(3);
    });

    it('returns a response for each request it receives', function (): void {
        $matcher = new FakeRouteMatcher();
        $router = new Router($matcher, new NullContainer());
        $psr7Worker = new FakePsr7Worker([
            new ServerRequest('GET', 'https://example.test/one'),
            new ServerRequest('GET', 'https://example.test/two'),
        ]);
        $handler = new WorkerRequestHandler(
            psr7Worker: $psr7Worker,
            router: $router,
            requestBridge: new Psr7RequestBridge(),
            responseBridge: new Psr7ResponseBridge(),
            logger: new WorkerLogger(),
            container: new NullContainer(),
        );

        $handler->run();

        expect($psr7Worker->responses)->toHaveCount(2)
            ->and($psr7Worker->responses[0]->getStatusCode())->toBe(404)
            ->and($psr7Worker->responses[1]->getStatusCode())->toBe(404);
    });

    it('converts an unhandled exception into a five hundred response', function (): void {
        $matcher = new FakeRouteMatcher(onMatch: function (): never {
            throw new RuntimeException('route matching exploded');
        });
        $router = new Router($matcher, new NullContainer());
        $psr7Worker = new FakePsr7Worker([
            new ServerRequest('GET', 'https://example.test/boom'),
        ]);
        $handler = new WorkerRequestHandler(
            psr7Worker: $psr7Worker,
            router: $router,
            requestBridge: new Psr7RequestBridge(),
            responseBridge: new Psr7ResponseBridge(),
            logger: new WorkerLogger(),
            container: new NullContainer(),
        );

        $handler->run();

        expect($psr7Worker->responses)->toHaveCount(1)
            ->and($psr7Worker->responses[0]->getStatusCode())->toBe(500);
    });

    it('omits exception details from the five hundred body outside development', function (): void {
        $matcher = new FakeRouteMatcher(onMatch: function (): never {
            throw new RuntimeException('super secret database credentials leaked here');
        });
        $router = new Router($matcher, new NullContainer());
        $psr7Worker = new FakePsr7Worker([
            new ServerRequest('GET', 'https://example.test/boom'),
        ]);
        $handler = new WorkerRequestHandler(
            psr7Worker: $psr7Worker,
            router: $router,
            requestBridge: new Psr7RequestBridge(),
            responseBridge: new Psr7ResponseBridge(),
            logger: new WorkerLogger(),
            container: new NullContainer(),
            development: false,
        );

        $handler->run();

        $body = (string) $psr7Worker->responses[0]->getBody();

        expect($body)->not->toContain('super secret database credentials leaked here')
            ->and($body)->not->toContain(RuntimeException::class);
    });

    it('continues serving after a request throws', function (): void {
        $matcher = new FakeRouteMatcher(onMatch: function (string $method, string $path): ?MatchedRoute {
            if ($path === '/boom') {
                throw new RuntimeException('request two exploded');
            }

            return null;
        });
        $router = new Router($matcher, new NullContainer());
        $psr7Worker = new FakePsr7Worker([
            new ServerRequest('GET', 'https://example.test/first'),
            new ServerRequest('GET', 'https://example.test/boom'),
            new ServerRequest('GET', 'https://example.test/third'),
        ]);
        $handler = new WorkerRequestHandler(
            psr7Worker: $psr7Worker,
            router: $router,
            requestBridge: new Psr7RequestBridge(),
            responseBridge: new Psr7ResponseBridge(),
            logger: new WorkerLogger(),
            container: new NullContainer(),
        );

        $handler->run();

        expect($psr7Worker->responses)->toHaveCount(3)
            ->and($psr7Worker->responses[0]->getStatusCode())->toBe(404)
            ->and($psr7Worker->responses[1]->getStatusCode())->toBe(500)
            ->and($psr7Worker->responses[2]->getStatusCode())->toBe(404);
    });

    it('stops looping when the worker signals no further requests', function (): void {
        $matcher = new FakeRouteMatcher();
        $router = new Router($matcher, new NullContainer());
        $psr7Worker = new FakePsr7Worker([
            new ServerRequest('GET', 'https://example.test/one'),
            new ServerRequest('GET', 'https://example.test/two'),
        ]);
        $handler = new WorkerRequestHandler(
            psr7Worker: $psr7Worker,
            router: $router,
            requestBridge: new Psr7RequestBridge(),
            responseBridge: new Psr7ResponseBridge(),
            logger: new WorkerLogger(),
            container: new NullContainer(),
        );

        $handler->run();

        expect($matcher->matchCount)->toBe(2)
            ->and($psr7Worker->responses)->toHaveCount(2);
    });

    it('captures stray application output instead of writing it to standard out', function (): void {
        $matcher = new FakeRouteMatcher(onMatch: function (): ?MatchedRoute {
            echo 'stray application output that must never reach the relay';

            return null;
        });
        $router = new Router($matcher, new NullContainer());
        $psr7Worker = new FakePsr7Worker([
            new ServerRequest('GET', 'https://example.test/noisy'),
        ]);
        $handler = new WorkerRequestHandler(
            psr7Worker: $psr7Worker,
            router: $router,
            requestBridge: new Psr7RequestBridge(),
            responseBridge: new Psr7ResponseBridge(),
            logger: new WorkerLogger(),
            container: new NullContainer(),
        );

        $handler->run();

        expect($psr7Worker->responses)->toHaveCount(1)
            ->and($psr7Worker->responses[0]->getStatusCode())->toBe(404);
    })->expectOutputString('');

    it('restores the output buffer level after a request throws', function (): void {
        $matcher = new FakeRouteMatcher(onMatch: function (): never {
            throw new RuntimeException('boom while buffering');
        });
        $router = new Router($matcher, new NullContainer());
        $psr7Worker = new FakePsr7Worker([
            new ServerRequest('GET', 'https://example.test/boom'),
        ]);
        $handler = new WorkerRequestHandler(
            psr7Worker: $psr7Worker,
            router: $router,
            requestBridge: new Psr7RequestBridge(),
            responseBridge: new Psr7ResponseBridge(),
            logger: new WorkerLogger(),
            container: new NullContainer(),
        );
        $levelBefore = ob_get_level();

        $handler->run();

        expect(ob_get_level())->toBe($levelBefore);
    });

    it('removes temporary upload files after each request', function (): void {
        $matcher = new FakeRouteMatcher(onMatch: function (): never {
            throw new RuntimeException('handler failed after the upload was bridged');
        });
        $router = new Router($matcher, new NullContainer());
        $psr7Worker = new FakePsr7Worker([
            (new ServerRequest('POST', 'https://example.test/upload'))->withUploadedFiles([
                'avatar' => new Psr7UploadedFile(Stream::create('one'), 3, UPLOAD_ERR_OK, 'one.png', 'image/png'),
            ]),
            (new ServerRequest('POST', 'https://example.test/upload'))->withUploadedFiles([
                'avatar' => new Psr7UploadedFile(Stream::create('two'), 3, UPLOAD_ERR_OK, 'two.png', 'image/png'),
            ]),
        ]);
        $requestBridge = new class () extends Psr7RequestBridge
        {
            /** @var list<string> */
            public array $tempPaths = [];

            public function bridge(
                ServerRequestInterface $psr7Request,
            ): Request {
                $request = parent::bridge($psr7Request);
                $this->tempPaths[] = $request->file('avatar')->tempPath();

                return $request;
            }
        };
        $handler = new WorkerRequestHandler(
            psr7Worker: $psr7Worker,
            router: $router,
            requestBridge: $requestBridge,
            responseBridge: new Psr7ResponseBridge(),
            logger: new WorkerLogger(),
            container: new NullContainer(),
        );

        $handler->run();

        expect($requestBridge->tempPaths)->toHaveCount(2)
            ->and(file_exists($requestBridge->tempPaths[0]))->toBeFalse()
            ->and(file_exists($requestBridge->tempPaths[1]))->toBeFalse();
    });
});
