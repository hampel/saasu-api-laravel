<?php

declare(strict_types=1);

namespace Hampel\Saasu\Api\Laravel\Facades;

use Hampel\Saasu\Api\Authentication\Authentication;
use Hampel\Saasu\Api\Client;
use Hampel\Saasu\Api\Config;
use Hampel\Saasu\Api\Connection;
use Hampel\Saasu\Api\Endpoint\Accounts;
use Hampel\Saasu\Api\Endpoint\Activities;
use Hampel\Saasu\Api\Endpoint\Attachments;
use Hampel\Saasu\Api\Endpoint\Authorisation;
use Hampel\Saasu\Api\Endpoint\Brands;
use Hampel\Saasu\Api\Endpoint\Companies;
use Hampel\Saasu\Api\Endpoint\ContactAggregates;
use Hampel\Saasu\Api\Endpoint\Contacts;
use Hampel\Saasu\Api\Endpoint\DeletedEntities;
use Hampel\Saasu\Api\Endpoint\Employees;
use Hampel\Saasu\Api\Endpoint\Endpoint;
use Hampel\Saasu\Api\Endpoint\Files;
use Hampel\Saasu\Api\Endpoint\Invoices;
use Hampel\Saasu\Api\Endpoint\ItemAdjustments;
use Hampel\Saasu\Api\Endpoint\Items;
use Hampel\Saasu\Api\Endpoint\ItemTransfers;
use Hampel\Saasu\Api\Endpoint\Journals;
use Hampel\Saasu\Api\Endpoint\LeaveRequests;
use Hampel\Saasu\Api\Endpoint\Lookups;
use Hampel\Saasu\Api\Endpoint\Payments;
use Hampel\Saasu\Api\Endpoint\PayrollEntries;
use Hampel\Saasu\Api\Endpoint\Reports;
use Hampel\Saasu\Api\Endpoint\Search;
use Hampel\Saasu\Api\Endpoint\TaxCodes;
use Hampel\Saasu\Api\Endpoint\Timesheets;
use Hampel\Saasu\Api\Endpoint\Users;
use Hampel\Saasu\Api\Entity\FileIdentity;
use Illuminate\Support\Facades\Facade;

/**
 * Facade for the Saasu API manager.
 *
 *     Saasu::contacts()->list();                        // the default connection
 *     Saasu::client('subsidiary')->invoices()->list();  // a named one
 *
 * The operations are one hop further in, so the annotations below cover the hop and the endpoint
 * classes carry the typed signatures from there. Without them every call through the facade is
 * untyped to both the IDE and PHPStan, which is most of what a facade costs you.
 *
 * Everything after configuredConnections() mirrors a method on the core package's Client, reached
 * through the manager's __call(). FacadeConformanceTest asserts that the two lists stay identical,
 * so a method added to the client in a later release shows up as a failing test rather than as a
 * call that silently loses its type.
 *
 * NOTE WHICH connection() THIS IS. It is the core package's transport, not a way to reach a
 * configured connection, which is client(). See SaasuManager.
 *
 * endpoint() is the one annotation that gives something up: on the client it is generic, returning
 * the class it was handed, and a @method line cannot express that. Reach for
 * `Saasu::client()->endpoint(Foo::class)` where the generic return matters.
 *
 * @method static Client client(?string $name = null)
 * @method static string getDefaultConnection()
 * @method static list<string> configuredConnections()
 * @method static Config config()
 * @method static Authentication authentication()
 * @method static FileIdentity verify()
 * @method static int requestsSent()
 * @method static Client withRequestBudget(?int $budget)
 * @method static Client withFileId(int $fileId)
 * @method static Client withConfig(Config $config)
 * @method static Client withCredential(Authentication $authentication)
 * @method static Endpoint endpoint(string $class)
 * @method static Accounts accounts()
 * @method static Activities activities()
 * @method static Attachments attachments()
 * @method static Authorisation authorisation()
 * @method static Brands brands()
 * @method static Companies companies()
 * @method static Contacts contacts()
 * @method static ContactAggregates contactAggregates()
 * @method static DeletedEntities deletedEntities()
 * @method static Employees employees()
 * @method static Files files()
 * @method static Invoices invoices()
 * @method static Items items()
 * @method static ItemAdjustments itemAdjustments()
 * @method static ItemTransfers itemTransfers()
 * @method static Journals journals()
 * @method static LeaveRequests leaveRequests()
 * @method static Lookups lookups()
 * @method static Payments payments()
 * @method static PayrollEntries payrollEntries()
 * @method static Reports reports()
 * @method static Search search()
 * @method static TaxCodes taxCodes()
 * @method static Timesheets timesheets()
 * @method static Users user()
 * @method static Connection connection()
 *
 * @see \Hampel\Saasu\Api\Laravel\SaasuManager
 * @see \Hampel\Saasu\Api\Client
 */
final class Saasu extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Hampel\Saasu\Api\Laravel\SaasuManager::class;
    }
}
