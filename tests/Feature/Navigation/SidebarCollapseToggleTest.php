<?php

beforeEach(function () {
    seedAccessControl();
});

it('renders the desktop sidebar collapse toggle in the authenticated shell', function () {
    $response = $this->actingAs(userInRole('Admin Klinik'))
        ->get(route('dashboard'))
        ->assertOk();

    // Toggle button marker + Alpine wiring.
    $response->assertSee('data-testid="sidebar-collapse-toggle"', false);
    $response->assertSee('$store.sidebar.toggleExpanded()', false);
    // Accessible label states for hide/show.
    $response->assertSee('Sembunyikan sidebar', false);
    $response->assertSee('Tampilkan sidebar', false);
});

it('binds main content margin to the expanded state so it reflows when collapsed', function () {
    $this->actingAs(userInRole('Admin Klinik'))
        ->get(route('dashboard'))
        ->assertOk()
        // Conditional margin: full offset when expanded, none when collapsed.
        ->assertSee("\$store.sidebar.isExpanded ? 'xl:ml-[290px]' : 'xl:ml-0'", false);
});

it('persists the sidebar preference under the adlms localStorage key', function () {
    // Persistence lives in app.js (JS, not rendered HTML); assert the source convention.
    $js = file_get_contents(resource_path('js/app.js'));

    expect($js)
        ->toContain('adlms-sidebar-expanded')
        ->toContain('persistExpanded()');
});
