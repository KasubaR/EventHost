<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Tests\TestCase;

/**
 * Submitting a login form twice (double click, back button, second tab) sends the
 * first token again after the successful login already regenerated it. That used
 * to render Laravel's raw 419 "Page Expired" screen even though the user was by
 * then signed in. The handler in bootstrap/app.php redirects instead.
 *
 * The CSRF middleware short-circuits under `runningUnitTests()`, so these drive
 * the exception handler directly rather than posting a stale token.
 */
class CsrfTokenMismatchTest extends TestCase
{
    use RefreshDatabase;

    private function renderMismatch(Request $request): mixed
    {
        $request->setLaravelSession($this->app['session.store']);

        return $this->app[ExceptionHandler::class]->render(
            $request,
            new TokenMismatchException('CSRF token mismatch.')
        );
    }

    private function loginRequest(): Request
    {
        return Request::create('/login', 'POST', [
            'email' => 'someone@example.com',
            'password' => 'secret-password',
        ]);
    }

    public function test_a_stale_token_redirects_instead_of_returning_419(): void
    {
        $response = $this->renderMismatch($this->loginRequest());

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame(302, $response->getStatusCode());
    }

    public function test_a_guest_is_told_the_session_expired(): void
    {
        $response = $this->renderMismatch($this->loginRequest());

        $errors = $this->app['session.store']->get('errors');

        $this->assertNotNull($errors);
        $this->assertStringContainsString('session expired', $errors->first('email'));
    }

    public function test_the_submitted_password_is_never_flashed_back(): void
    {
        $this->renderMismatch($this->loginRequest());

        $old = $this->app['session.store']->get('_old_input', []);

        $this->assertSame('someone@example.com', $old['email'] ?? null);
        $this->assertArrayNotHasKey('password', $old);
        $this->assertArrayNotHasKey('_token', $old);
    }

    public function test_an_already_authenticated_user_is_not_shown_an_error(): void
    {
        $user = User::factory()->create();

        $request = $this->loginRequest();
        $request->setUserResolver(fn () => $user);

        $response = $this->renderMismatch($request);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertNull($this->app['session.store']->get('errors'));
    }

    public function test_json_clients_still_get_a_419_with_a_readable_message(): void
    {
        $request = Request::create('/login', 'POST', [], [], [], [
            'HTTP_ACCEPT' => 'application/json',
        ]);

        $response = $this->renderMismatch($request);

        $this->assertSame(419, $response->getStatusCode());
        $this->assertStringContainsString('session expired', $response->getContent());
    }
}
