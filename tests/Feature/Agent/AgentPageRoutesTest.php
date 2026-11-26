<?php

use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Http\Request;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Screen URLs under /agent. Some share a URL with a JSON index: an Inertia or
 * browser navigation gets the page, an axios/JSON call gets the API.
 *
 * Page components are asserted with `component($name, false)` so the test
 * does not depend on the .tsx file existing yet (the frontend builds them).
 */

dataset('agent pages', [
    'proposals' => ['/agent/proposals', 'Agent/Proposals/Index'],
    'simulations' => ['/agent/simulations', 'Agent/Simulations/Index'],
    'mining' => ['/agent/mining', 'Agent/Mining/Index'],
    'manager' => ['/agent/manager', 'Agent/Manager/Index'],
    'qa' => ['/agent/qa', 'Agent/Qa/Index'],
    'reports' => ['/agent/reports', 'Agent/Reports/Index'],
    'shadow' => ['/agent/shadow', 'Agent/Shadow/Index'],
    'actions queue' => ['/agent/actions', 'Agent/Actions/Index'],
    'action policies' => ['/agent/actions/policies', 'Agent/Actions/Policies'],
    'action audit' => ['/agent/actions/audit', 'Agent/Actions/Audit'],
]);

it('renders the page for a browser navigation', function (string $url, string $component) {
    $this->actingAs(adminUser())->get($url)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component($component, false));
})->with('agent pages');

it('renders the page for an Inertia visit', function (string $url, string $component) {
    $version = (string) app(HandleInertiaRequests::class)->version(Request::create('/'));
    $this->actingAs(adminUser())
        ->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => $version, 'X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'text/html, application/xhtml+xml'])
        ->get($url)
        ->assertOk()
        ->assertHeader('X-Inertia', 'true')
        ->assertJsonPath('component', $component);
})->with('agent pages');

it('keeps the JSON index for axios calls', function (string $url, string $key) {
    // axios default headers (resources/js/bootstrap.ts adds X-Requested-With).
    $this->actingAs(adminUser())
        ->withHeaders(['Accept' => 'application/json, text/plain, */*', 'X-Requested-With' => 'XMLHttpRequest'])
        ->get($url)
        ->assertOk()
        ->assertHeader('Content-Type', 'application/json')
        ->assertJsonStructure([$key]);
})->with([
    'proposals' => ['/agent/proposals', 'data'],
    'simulations' => ['/agent/simulations', 'data'],
    'mining' => ['/agent/mining', 'runs'],
    'action policies' => ['/agent/actions/policies', 'policies'],
    'action audit' => ['/agent/actions/audit', 'data'],
]);

it('forbids admin-only pages to agents', function (string $url) {
    $this->actingAs(agentUser())->get($url)->assertForbidden();
})->with([
    '/agent/proposals', '/agent/simulations', '/agent/mining', '/agent/manager', '/agent/qa',
    '/agent/reports', '/agent/shadow', '/agent/actions/policies', '/agent/actions/audit',
]);

it('still streams the audit CSV for a download link', function () {
    $response = $this->actingAs(adminUser())->get('/agent/actions/audit?format=csv')->assertOk();
    expect($response->headers->get('Content-Type'))->toContain('text/csv');
});
