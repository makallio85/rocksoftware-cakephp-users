<?php
declare(strict_types=1);

namespace RockSoftware\Users\Middleware;

use Cake\Core\Configure;
use Cake\Http\Response;
use Cake\Routing\Router;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Every page needs a signed-in session, except the sign-in routes
 * (`SIGN_IN_PATHS`) and `RockSoftwareUsers.publicPaths`. The settings page is not one of
 * them. Added by the plugin when it provides the
 * application's authentication, so an application protects its pages without a
 * line of code.
 */
class RequireSignInMiddleware implements MiddlewareInterface
{
    /**
     * The routes a person reaches before they are signed in.
     *
     * @var list<string>
     */
    public const SIGN_IN_PATHS = [
        '/auth',
        '/auth/start',
        '/auth/password',
        '/auth/callback',
        '/auth/sign-out',
        '/auth/backchannel-logout',
        '/auth/provision',
        '/auth/client-metadata',
        '/auth/emergency/*',
    ];

    /**
     * @param \Psr\Http\Message\ServerRequestInterface $request The request.
     * @param \Psr\Http\Server\RequestHandlerInterface $handler The handler.
     * @return \Psr\Http\Message\ResponseInterface
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        if ($request->getAttribute('identity') !== null || self::isPublic($path)) {
            return $handler->handle($request);
        }

        if (str_contains($request->getHeaderLine('Accept'), 'application/json')) {
            return (new Response())
                ->withStatus(401)
                ->withType('application/json')
                ->withStringBody((string)json_encode(['error' => 'Sign-in required.']));
        }

        $query = $request->getUri()->getQuery();
        $redirect = $path . ($query !== '' ? '?' . $query : '');
        $url = Router::url(Configure::read('RockSoftwareUsers.signInUrl'), true);
        $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query(['redirect' => $redirect]);

        return (new Response())->withStatus(302)->withLocation($url);
    }

    /**
     * @param string $path The request path.
     * @return bool
     */
    public static function isPublic(string $path): bool
    {
        $patterns = array_merge(self::SIGN_IN_PATHS, (array)Configure::read('RockSoftwareUsers.publicPaths', []));
        foreach ($patterns as $pattern) {
            if (is_string($pattern) && fnmatch($pattern, $path)) {
                return true;
            }
        }

        return false;
    }
}
