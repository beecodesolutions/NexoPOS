<?php

/**
 * NexoPOS Controller
 *
 * @since  1.0
 **/

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\DashboardController;
use Illuminate\Http\RedirectResponse;

class HomeController extends DashboardController
{
    public function welcome(): RedirectResponse
    {
        $route = auth()->check() ? 'ns.dashboard.home' : 'ns.login';

        return redirect( ns()->route( $route ) );
    }
}
