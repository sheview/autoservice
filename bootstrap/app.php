<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Modules\Platform\Http\Middleware\ApplySessionTimeout;
use App\Modules\Platform\Http\Middleware\EnsureModuleEnabled;
use App\Modules\Platform\Http\Middleware\LogImpersonatedRequests;
use App\Modules\Tenancy\Http\Middleware\EnforceSubscription;
use App\Modules\Tenancy\Http\Middleware\ResolveTenant;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Inertia\Middleware\EncryptHistory;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Before StartSession, which reads session.lifetime.
        $middleware->web(prepend: [ApplySessionTimeout::class]);

        $middleware->web(append: [
            ResolveTenant::class,
            EnforceSubscription::class,
            LogImpersonatedRequests::class,
            // Pages kept in the browser history are encrypted with a key that logout throws away
            // (AuthenticatedSessionController::destroy), so Back cannot show them after signing out.
            EncryptHistory::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        // The tenant must be set before route model binding, or {asset} / {user} would be
        // looked up without a tenant (tenant scope = no rows) and every such URL would 404.
        $middleware->prependToPriorityList(SubstituteBindings::class, ResolveTenant::class);

        $middleware->alias([
            'module' => EnsureModuleEnabled::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Signed out while a page loads a file (a photo, the logo — an <img> asks for images, not a
        // page): 401, not the login page, and not kept as where to go after signing in.
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            $accept = (string) $request->header('Accept', '');
            if ($accept !== '' && ! $request->expectsJson() && ! $request->header('X-Inertia') && ! str_contains($accept, 'text/html')) {
                return response('', 401);
            }

            return null;
        });

        // A body larger than post_max_size arrives empty: say so in Thai instead of an error page
        // (the session has not started yet, so no redirect with errors). The pages show this
        // message from the 413 (resources/js/lib/uploadNotice.ts).
        $exceptions->render(function (PostTooLargeException $e, Request $request) {
            $message = __('ui.upload_too_large', ['max' => ini_get('post_max_size').'B']);

            return $request->expectsJson()
                ? response()->json(['message' => $message], 413)
                : response($message, 413)->header('Content-Type', 'text/plain; charset=UTF-8');
        });

        // A form sent from a page opened before its session ended (left open past SESSION_LIFETIME,
        // or loaded before a deploy) fails the CSRF check: instead of Laravel's bare "419 PAGE EXPIRED"
        // (shown by Inertia as a blank popup), back to the same page — it loads with a new token —
        // with a message to send it again. `status` is what the sign-in pages show, `error` the others.
        $exceptions->respond(function (Response $response, Throwable $e, Request $request) {
            if ($response->getStatusCode() !== 419 || $request->expectsJson()) {
                return $response;
            }

            $message = __('ui.page_expired');

            return back()->with(['status' => $message, 'error' => $message]);
        });
    })->create();
