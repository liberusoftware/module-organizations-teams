<?php

declare(strict_types=1);

use App\Events\ContactUpdated;
use Filament\Facades\Filament;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route as RouteFacade;
use Laravel\Jetstream\Events\TeamMemberAdded;
use Liberu\Foundation\Organizations\Actions\Jetstream\CreateTeam as CreateTeamAction;
use Liberu\Foundation\Organizations\Actions\Jetstream\InviteTeamMember;
use Liberu\Foundation\Organizations\Http\Controllers\TeamInvitationController;
use Liberu\Foundation\Organizations\Http\Middleware\AssignDefaultTeam;
use Liberu\Foundation\Organizations\Http\Middleware\SetFilamentDefaultTenant;
use Liberu\Foundation\Organizations\Http\Middleware\SetPermissionsTeamContext;
use Liberu\Foundation\Organizations\Http\Middleware\SetTenantContext;
use Liberu\Foundation\Organizations\Http\Middleware\TeamsPermission;
use Liberu\Foundation\Organizations\Listeners\AssignDefaultTeamRole;
use Liberu\Foundation\Organizations\Listeners\CreatePersonalTeam;
use Liberu\Foundation\Organizations\Listeners\NotifyTeamMembers;
use Liberu\Foundation\Organizations\Listeners\SwitchTeam;
use Liberu\Foundation\Organizations\Livewire\CreateTeam as CreateTeamLivewire;
use Liberu\Foundation\Organizations\Models\Team;
use Liberu\Foundation\Organizations\Models\TeamInvitation;
use Liberu\Foundation\Organizations\Services\TeamManagementService;
use Liberu\Foundation\Organizations\Tests\Fixtures\OrgTestUser;
use Liberu\Foundation\RolesPermissions\Enums\Role;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role as SpatieRole;
use Symfony\Component\HttpKernel\Exception\HttpException;

function createHttpUser(array $overrides = []): OrgTestUser
{
    static $seq = 0;
    $seq++;

    return OrgTestUser::forceCreate(array_merge([
        'name' => "Http User {$seq}",
        'email' => "http-user-{$seq}-".uniqid().'@example.test',
        'password' => bcrypt('Password123!@#'),
    ], $overrides));
}

function createHttpTeam(OrgTestUser $owner, array $overrides = []): Team
{
    return Team::forceCreate(array_merge([
        'user_id' => $owner->id,
        'name' => 'Http Team '.uniqid(),
        'personal_team' => false,
    ], $overrides));
}

afterEach(function () {
    Auth::logout();
    Filament::setTenant(null);
});

it('covers TeamInvitationController sendInvitation and acceptInvitation', function () {
    Mail::fake();
    Event::fake([TeamMemberAdded::class]);

    $owner = createHttpUser();
    $invitee = createHttpUser(['email' => 'invited-ctrl@example.test']);
    $other = createHttpUser(['email' => 'other-ctrl@example.test']);
    $team = createHttpTeam($owner);

    $controller = new TeamInvitationController();

    // sendInvitation
    $sendReq = Request::create('/invitations', 'POST', [
        'email' => $invitee->email,
        'role' => 'editor',
        'team_id' => $team->id,
    ]);
    $sendReq->setUserResolver(fn () => $owner);

    $resp = $controller->sendInvitation($sendReq, new InviteTeamMember());
    expect($resp->getStatusCode())->toBe(302);

    $invitation = TeamInvitation::where('email', $invitee->email)->firstOrFail();

    // acceptInvitation unauthenticated -> 401
    $guestReq = Request::create('/invitations/accept', 'GET');
    expect(fn () => $controller->acceptInvitation($guestReq, $invitation->id))
        ->toThrow(HttpException::class);

    // acceptInvitation wrong email -> 403
    $wrongUserReq = Request::create('/invitations/accept', 'GET');
    $wrongUserReq->setUserResolver(fn () => $other);
    expect(fn () => $controller->acceptInvitation($wrongUserReq, $invitation->id))
        ->toThrow(HttpException::class);

    // acceptInvitation invalid role -> 403
    $badInv = TeamInvitation::create([
        'team_id' => $team->id,
        'email' => $other->email,
        'role' => 'super_admin',
    ]);
    expect(fn () => $controller->acceptInvitation($wrongUserReq, $badInv->id))
        ->toThrow(HttpException::class);

    // acceptInvitation with editor (mapped to SalesRep)
    $acceptReq = Request::create('/invitations/accept', 'GET');
    $acceptReq->setUserResolver(fn () => $invitee);
    $acceptResp = $controller->acceptInvitation($acceptReq, $invitation->id);

    expect($acceptResp->getStatusCode())->toBe(302)
        ->and($invitee->fresh()->current_team_id)->toBe($team->id)
        ->and(TeamInvitation::find($invitation->id))->toBeNull();
    Event::assertDispatched(TeamMemberAdded::class);

    // acceptInvitation with manager (triggers provisionTeamRole)
    $managerInvitee = createHttpUser(['email' => 'mgr-invitee@example.test']);
    $mgrInv = TeamInvitation::create([
        'team_id' => $team->id,
        'email' => $managerInvitee->email,
        'role' => Role::Manager->value,
    ]);
    $mgrReq = Request::create('/invitations/accept', 'GET');
    $mgrReq->setUserResolver(fn () => $managerInvitee);
    $mgrResp = $controller->acceptInvitation($mgrReq, $mgrInv->id);
    expect($mgrResp->getStatusCode())->toBe(302)
        ->and($managerInvitee->fresh()->current_team_id)->toBe($team->id);
});

it('covers AssignDefaultTeam, SetFilamentDefaultTenant, SetPermissionsTeamContext, and SetTenantContext middleware', function () {
    $next = fn (Request $req): Response => new Response('ok', 200);
    $req = Request::create('/test', 'GET');

    // AssignDefaultTeam: guest
    Filament::setTenant(null);
    $mw = new AssignDefaultTeam();
    expect($mw->handle($req, $next)->getStatusCode())->toBe(200)
        ->and(Filament::getTenant())->toBeNull();

    // AssignDefaultTeam: user with no teams creates personal team
    $userNoTeam = createHttpUser();
    Auth::login($userNoTeam);
    $mw->handle($req, $next);
    expect(Filament::getTenant())->toBeInstanceOf(Team::class)
        ->and($userNoTeam->fresh()->current_team_id)->not->toBeNull();

    // AssignDefaultTeam: user with existing team
    Filament::setTenant(null);
    $mw->handle($req, $next);
    expect(Filament::getTenant()?->id)->toBe($userNoTeam->fresh()->current_team_id);

    // SetFilamentDefaultTenant
    Filament::setTenant(null);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $defaultTenantMw = new SetFilamentDefaultTenant();
    expect($defaultTenantMw->handle($req, $next)->getStatusCode())->toBe(200);
    // Already set branch
    expect($defaultTenantMw->handle($req, $next)->getStatusCode())->toBe(200);

    // Tenancy wrapper middlewares
    $permMw = new SetPermissionsTeamContext();
    $tenantMw = new SetTenantContext();
    expect($permMw->handle($req, $next)->getStatusCode())->toBe(200)
        ->and($tenantMw->handle($req, $next)->getStatusCode())->toBe(200);
});

it('covers TeamsPermission middleware across all branches', function () {
    if (! RouteFacade::has('login')) {
        RouteFacade::get('/login', fn () => 'login')->name('login');
    }
    if (! RouteFacade::has('home')) {
        RouteFacade::get('/', fn () => 'home')->name('home');
    }

    $mw = new TeamsPermission();
    $next = fn (Request $req): Response => new Response('allowed', 200);

    // Guest -> redirect to login
    Auth::logout();
    $guestResp = $mw->handle(Request::create('/app', 'GET'), $next);
    expect($guestResp->getStatusCode())->toBe(302);

    // Admin passes through
    $admin = createHttpUser();
    $team = createHttpTeam($admin);
    setPermissionsTeamId($team->id);
    $adminRole = SpatieRole::firstOrCreate(['name' => Role::Admin->value, 'guard_name' => 'web', 'team_id' => null]);
    $admin->assignRole($adminRole);
    Auth::login($admin);
    expect($mw->handle(Request::create('/app', 'GET'), $next)->getContent())->toBe('allowed');

    // Non-admin user without currentTeam passes through
    $user = createHttpUser();
    Auth::login($user);
    expect($mw->handle(Request::create('/app', 'GET'), $next)->getContent())->toBe('allowed');

    // User with currentTeam
    $user->forceFill(['current_team_id' => $team->id])->save();
    $user = $user->fresh();
    Auth::login($user);

    // Requested tenant mismatch -> redirect home
    $mismatchReq = Request::create('/app/999', 'GET');
    $mismatchRoute = new Route('GET', '/app/{tenant}', fn () => 'ok');
    $mismatchRoute->bind($mismatchReq);
    $mismatchRoute->setParameter('tenant', 999);
    $mismatchReq->setRouteResolver(fn () => $mismatchRoute);
    expect($mw->handle($mismatchReq, $next)->getStatusCode())->toBe(302);

    // Route with no name -> allowed
    $unnamedReq = Request::create('/app/other', 'GET');
    $unnamedRoute = new Route('GET', '/app/other', fn () => 'ok');
    $unnamedRoute->bind($unnamedReq);
    $unnamedReq->setRouteResolver(fn () => $unnamedRoute);
    expect($mw->handle($unnamedReq, $next)->getContent())->toBe('allowed');

    // Non-filament named route -> allowed
    $nonFilReq = Request::create('/dashboard', 'GET');
    $nonFilRoute = (new Route('GET', '/dashboard', fn () => 'ok'))->name('dashboard');
    $nonFilRoute->bind($nonFilReq);
    $nonFilReq->setRouteResolver(fn () => $nonFilRoute);
    expect($mw->handle($nonFilReq, $next)->getContent())->toBe('allowed');

    // Filament route with unmapped action -> allowed
    $unmappedReq = Request::create('/app/invoices/custom', 'GET');
    $unmappedRoute = (new Route('GET', '/app/invoices/custom', fn () => 'ok'))->name('filament.app.resources.invoices.custom');
    $unmappedRoute->bind($unmappedReq);
    $unmappedReq->setRouteResolver(fn () => $unmappedRoute);
    expect($mw->handle($unmappedReq, $next)->getContent())->toBe('allowed');

    // Filament route where permission does not exist in DB -> allowed
    $noPermReq = Request::create('/app/widgets', 'GET');
    $noPermRoute = (new Route('GET', '/app/widgets', fn () => 'ok'))->name('filament.app.resources.widgets.index');
    $noPermRoute->bind($noPermReq);
    $noPermReq->setRouteResolver(fn () => $noPermRoute);
    expect($mw->handle($noPermReq, $next)->getContent())->toBe('allowed');

    // Filament route where permission exists: denied then allowed
    $perm = Permission::firstOrCreate(['name' => 'view:Invoice', 'guard_name' => 'web']);
    $permReq = Request::create('/app/invoices', 'GET');
    $permRoute = (new Route('GET', '/app/invoices', fn () => 'ok'))->name('filament.app.resources.invoices.index');
    $permRoute->bind($permReq);
    $permReq->setRouteResolver(fn () => $permRoute);

    expect($mw->handle($permReq, $next)->getStatusCode())->toBe(302);

    $user->givePermissionTo($perm);
    expect($mw->handle($permReq, $next)->getContent())->toBe('allowed');
});

it('covers AssignDefaultTeamRole, CreatePersonalTeam, NotifyTeamMembers, and SwitchTeam listeners', function () {
    $owner = createHttpUser();
    $member = createHttpUser();
    $team = createHttpTeam($owner);
    $team->users()->attach($member, ['role' => 'member']);

    $assignListener = new AssignDefaultTeamRole(new TeamManagementService());
    $assignListener->handle(new TeamMemberAdded($team, $member));
    setPermissionsTeamId($team->id);
    expect($member->fresh()->hasRole(Role::SalesRep->value))->toBeTrue();

    // Non-matching types ignored safely
    $assignListener->handle(new TeamMemberAdded(new stdClass(), new stdClass()));

    // CreatePersonalTeam listener
    $newUser = createHttpUser();
    (new CreatePersonalTeam(new TeamManagementService()))->handle(new Registered($newUser));
    expect($newUser->fresh()->current_team_id)->not->toBeNull();

    // NotifyTeamMembers listener
    (new NotifyTeamMembers())->handle(new ContactUpdated());

    // SwitchTeam listener
    $switchListener = new SwitchTeam();
    expect($switchListener)->toBeInstanceOf(\Liberu\Foundation\Tenancy\Listeners\SwitchTeam::class);
});

it('covers Livewire CreateTeam component', function () {
    if (! RouteFacade::has('filament.app.tenant.profile')) {
        RouteFacade::get('/app/{tenant}/profile', fn () => 'profile')->name('filament.app.tenant.profile');
    }

    $user = createHttpUser();
    Auth::login($user);

    $component = new CreateTeamLivewire();
    $component->state = ['name' => 'Livewire Created Team'];

    $response = $component->createTeam(new CreateTeamAction());

    expect($response)->not->toBeNull()
        ->and(Team::where('name', 'Livewire Created Team')->exists())->toBeTrue();
});
