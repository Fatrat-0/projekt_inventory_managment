<?php

namespace App\View\Components;

use App\Support\AppNavigation;
use App\Support\{AppSearch, AppTasks};
use Illuminate\View\Component;
use Illuminate\View\View;

class AppLayout extends Component
{
    /**
     * Get the view / contents that represents the component.
     */
    public function render(): View
    {
        return view('layouts.app', [
            'navigationGroups' => AppNavigation::groups(auth()->user()),
            'breadcrumbs' => AppNavigation::breadcrumbs(request()),
            'quickSearch' => auth()->user() ? AppSearch::results(auth()->user(), '') : ['results' => [], 'moreUrl' => null],
            'appTasks' => AppTasks::summary(auth()->user()),
        ]);
    }
}
