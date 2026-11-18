<?php

namespace App\Services;

use App\Models\BillingAccount;
use App\Models\Patient;
use App\Models\StockStoreAccess;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class ReportAccess
{
    public const ACTIVITY=['registry','appointments','encounters','admissions','clinical','prescriptions','care','diagnostics','results','medications','staff','reminders','notifications'];
    public const REQUIRED=[
        'registry'=>['patients.view'],'appointments'=>['appointments.view'],
        'encounters'=>['patients.view','encounters.view'],'admissions'=>['patients.view','encounters.view','admissions.view'],
        'clinical'=>['patients.view','encounters.view','clinical.view'],'prescriptions'=>['patients.view','encounters.view','prescriptions.view'],
        'care'=>['patients.view','encounters.view','care.view'],'diagnostics'=>['patients.view','encounters.view','investigations.view'],
        'results'=>['patients.view','encounters.view','investigations.view','investigations.results.view'],
        'medications'=>['patients.view','encounters.view','prescriptions.view','medications.view'],
        'reminders'=>['reminders.view'],'notifications'=>['notifications.view'],
        'inventory'=>['stock.view'],'billing'=>['patients.view','billing.view'],'hmo'=>['patients.view','billing.view','hmo.view'],
    ];

    public function allowed(User $actor, string $module): bool
    {
        if ($module==='staff') {
            return $actor->hasPermission('staff.view_self')||$actor->hasPermission('staff.view_department')||$actor->hasPermission('staff.view_any');
        }
        foreach (self::REQUIRED[$module]??[] as $permission) {
            if (!$actor->hasPermission($permission)) { return false; }
        }
        return isset(self::REQUIRED[$module]);
    }

    public function available(User $actor): array
    {
        return array_values(array_filter([...self::ACTIVITY,'inventory','billing','hmo'],fn (string $module): bool=>$this->allowed($actor,$module)));
    }

    public function accounts(User $actor, array $filters): Builder
    {
        $query=BillingAccount::query()->whereIn('patient_id',Patient::query()->visibleTo($actor)->select('patients.id'));
        if (isset($filters['patient_id'])) { $query->where('patient_id',$filters['patient_id']); }
        return $query;
    }

    public function stores(User $actor): Builder
    {
        return StockStoreAccess::query()->where('user_id',$actor->id)->where('is_active',true)->select('store_id');
    }
}
