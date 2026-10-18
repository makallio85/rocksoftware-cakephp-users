<?php
declare(strict_types=1);

namespace RockSoftware\Users\Middleware;

use Cake\Http\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RockSoftware\Users\Service\Provisioning;
use RockSoftware\Users\UsersPlugin;

/**
 * Answers `POST /auth/provision` itself, before the application's CSRF check
 * and sign-in requirement: the bearer token is what authorises it. Every other
 * request passes through. The body, which carries the client secret, is read
 * here and nowhere else, so no later error page or log can show it.
 */
class ProvisionMiddleware implements MiddlewareInterface
{
    /**
     * @param \Psr\Http\Message\ServerRequestInterface $request The request.
     * @param \Psr\Http\Server\RequestHandlerInterface $handler The handler.
     * @return \Psr\Http\Message\ResponseInterface
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($request->getUri()->getPath() !== UsersPlugin::PROVISION_PATH) {
            return $handler->handle($request);
        }
        UsersPlugin::registerApplicationEvents();
        if (Provisioning::config() === null) {
            return (new Response())->withStatus(404);
        }
        if ($request->getMethod() !== 'POST') {
            return (new Response())->withStatus(405)->withHeader('Allow', 'POST');
        }

        $status = (new Provisioning())->handle(
            $request->getHeaderLine('Authorization'),
            (string)$request->getBody(),
            $request->getServerParams()['REMOTE_ADDR'] ?? null,
        );

        return (new Response())->withStatus($status)->withHeader('Cache-Control', 'no-store');
    }
}
