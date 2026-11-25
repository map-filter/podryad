<?php

namespace App\Http\Controllers\Api;

use App\Models\Organization;
use App\Models\User;
use App\Services\ReadinessReport;
use Illuminate\Http\Request;

trait Concerns
{
    protected function org(Request $request): Organization
    {
        return $request->user()->organization;
    }

    protected function report(Request $request): ReadinessReport
    {
        return new ReadinessReport($this->org($request));
    }

    protected function requireEdit(Request $request): void
    {
        if (! $request->user()->canEdit()) {
            abort(403, 'Изменять данные могут руководитель и специалист по ОТ');
        }
    }

    protected function requireManage(Request $request): void
    {
        if (! $request->user()->canManage()) {
            abort(403, 'Действие доступно только руководителю');
        }
    }

    protected function user(Request $request): User
    {
        return $request->user();
    }
}
