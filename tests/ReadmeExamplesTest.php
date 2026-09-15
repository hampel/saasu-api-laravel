<?php

declare(strict_types=1);

namespace Hampel\Saasu\Api\Laravel\Tests;

use Hampel\Saasu\Api\Entity\Contact;
use Hampel\Saasu\Api\Entity\FileIdentity;
use Hampel\Saasu\Api\Laravel\Facades\Saasu;
use Hampel\Saasu\Api\Laravel\SaasuManager;
use Hampel\Saasu\Api\Result\Page;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;

/**
 * The README's examples, run.
 *
 * A pasted snippet gets hand-edited when the code around it changes and quietly stops matching what
 * the package does. These are the ones with a signature in them, so a renamed accessor, a changed
 * argument name or a changed return type fails here rather than being discovered by somebody
 * following the documentation.
 */
final class ReadmeExamplesTest extends TestCase
{
    #[Test]
    public function the_usage_examples_return_what_the_readme_says_they_do(): void
    {
        $this->fakeSaasu([
            'api.saasu.com/FileIdentity*' => Http::response(['Name' => 'Example Pty Ltd']),
            'api.saasu.com/Contacts*' => Http::response(self::contacts([self::contact()])),
            'api.saasu.com/Contact/54353*' => Http::response(self::contact()),
        ]);

        $file = Saasu::verify();
        $contacts = Saasu::contacts()->list();
        $contact = Saasu::contacts()->get(54353);

        $this->assertInstanceOf(FileIdentity::class, $file);
        $this->assertSame('Example Pty Ltd', $file->name);
        $this->assertInstanceOf(Page::class, $contacts);
        $this->assertSame(100, $contacts->pageSize);
        $this->assertInstanceOf(Contact::class, $contact);
    }

    #[Test]
    public function a_named_connection_and_an_injected_manager_are_reached_the_way_the_readme_says(): void
    {
        $this->container()->make(Config::class)->set('saasu.connections.subsidiary', [
            'file_id' => 22222,
            'username' => self::USERNAME,
            'password' => 'password-under-test',
        ]);

        $this->fakeSaasu(['api.saasu.com/*' => Http::response(['Invoices' => [], 'Contacts' => []])]);

        Saasu::client('subsidiary')->invoices()->list();
        $this->container()->make(SaasuManager::class)->client('subsidiary')->contacts()->list();

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'Invoices?FileId=22222'));
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'Contacts?FileId=22222'));
    }

    #[Test]
    public function a_connection_without_a_file_lists_the_files_its_login_reaches(): void
    {
        $this->container()->make(Config::class)->set('saasu.connections.files', [
            'username' => self::USERNAME,
            'password' => 'password-under-test',
        ]);

        $this->fakeSaasu(['api.saasu.com/FileIdentities*' => Http::response(['FileIdentities' => [['Id' => 12345, 'Name' => 'Example Pty Ltd']]])]);

        $files = Saasu::client('files')->files()->all();

        $this->assertCount(1, $files);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'FileIdentities')
            && ! str_contains($request->url(), 'FileId='));
    }

    #[Test]
    public function a_job_budget_counts_from_zero_on_a_client_that_has_already_sent_requests(): void
    {
        Http::fake(['api.saasu.com/*' => Http::response(self::contact())]);

        $legacy = Saasu::client('legacy');
        $legacy->contacts()->get(54353);

        $saasu = $legacy->withRequestBudget(1);
        $this->assertSame('Joe', $saasu->contacts()->get(54353)->givenName);
        Http::assertSentCount(2);

        $this->expectException(\Hampel\Saasu\Api\Exception\RequestBudgetExhaustedException::class);

        $saasu->contacts()->get(54353);
    }

    #[Test]
    public function the_testing_example_runs_as_written(): void
    {
        // The throttle the README tells a consumer to turn off is already off in this suite.
        Http::preventStrayRequests();

        Http::fake([
            'api.saasu.com/authorisation/*' => Http::response([
                'access_token' => 'test-token',
                'expires_in' => 10800,
            ]),
            'api.saasu.com/Contact/*' => Http::response([
                'Id' => 54353,
                'GivenName' => 'Joe',
            ]),
        ]);

        $contact = Saasu::contacts()->get(54353);

        $this->assertSame('Joe', $contact->givenName);
        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer test-token'));
    }
}
