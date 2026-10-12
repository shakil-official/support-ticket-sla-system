<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

/**
 * The Laravel 12 skeleton ships an empty Controller; the AuthorizesRequests trait is added
 * here so every controller can call $this->authorize() against the policies.
 */
abstract class Controller
{
    use AuthorizesRequests;
}
