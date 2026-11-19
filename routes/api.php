<?php

use App\Http\Controllers\Api\V1\AccessControlController;
use App\Http\Controllers\Api\V1\AppointmentController;
use App\Http\Controllers\Api\V1\AuditHistoryController;
use App\Http\Controllers\Api\V1\ClinicalController;
use App\Http\Controllers\Api\V1\DepartmentController;
use App\Http\Controllers\Api\V1\EncounterController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\PatientController;
use App\Http\Controllers\Api\V1\PatientHistoryController;
use App\Http\Controllers\Api\V1\PatientRecordController;
use App\Http\Controllers\Api\V1\PrescriptionController;
use App\Http\Controllers\Api\V1\ProtectedFileController;
use App\Http\Controllers\Api\V1\StaffDirectoryController;
use App\Http\Controllers\Api\V1\StaffManagementController;
use App\Http\Controllers\Api\V1\StaffRecoveryController;
use App\Http\Controllers\Api\V1\StaffSessionController;
use App\Http\Middleware\EnsureStaffIsActive;
use App\Http\Middleware\PreventAuthResponseCaching;
use App\Models\Department;
use App\Http\Controllers\Api\V1\AdmissionController;
use App\Http\Controllers\Api\V1\LocationController;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('api.v1.')->group(function (): void {
    Route::get('health', HealthController::class)->name('health');

    Route::middleware(['web', PreventAuthResponseCaching::class])->group(function (): void {
        Route::get('auth/csrf-token', [StaffSessionController::class, 'csrf'])->name('auth.csrf');
        Route::post('auth/login', [StaffSessionController::class, 'store'])
            ->middleware('throttle:staff-login')->name('auth.login');
        Route::post('auth/password/reset', StaffRecoveryController::class)
            ->middleware('throttle:staff-recovery')->name('auth.password.reset');

        Route::middleware(['auth:web', EnsureStaffIsActive::class, 'auth.session'])->group(function (): void {
            Route::prefix('exports')->name('exports.')->middleware('throttle:exports')->group(function (): void {
                $controller=\App\Http\Controllers\Api\V1\ExportController::class;
                Route::get('options',[$controller,'options'])->middleware('can:exports.generate')->name('options');
                Route::post('/',[$controller,'store'])->middleware('can:exports.generate')->name('store');
                Route::middleware('can:exports.download')->where(['export'=>'[1-9][0-9]{0,17}'])->group(function () use ($controller): void {
                    Route::get('{export}',[$controller,'show'])->name('show');
                    Route::get('{export}/download',[$controller,'download'])->name('download');
                    Route::get('{export}/print',[$controller,'download'])->name('print');
                });
            });
            Route::get('dashboard',[\App\Http\Controllers\Api\V1\ReportController::class,'show'])->middleware(['can:dashboard.view','throttle:reports'])->name('dashboard');
            Route::prefix('reports')->name('reports.')->middleware('throttle:reports')->group(function (): void {
                foreach (['options','activity','trend','inventory','positions','movements','billing','accounts','hmo'] as $kind) {
                    Route::get($kind,[\App\Http\Controllers\Api\V1\ReportController::class,'show'])->defaults('kind',$kind)
                        ->middleware('can:'.($kind==='options'?'reports.discover':\App\Services\ReportSnapshots::reportPermission($kind)))->name($kind);
                }
            });
            Route::prefix('reminders')->name('reminders.')->middleware('can:reminders.view')->group(function (): void {
                $controller=\App\Http\Controllers\Api\V1\ReminderController::class;
                Route::get('options',[$controller,'options'])->name('options');
                Route::get('recipients',[$controller,'recipients'])->middleware('can:reminders.assign')->name('recipients');
                Route::get('/',[$controller,'index'])->name('index');
                Route::get('{reminder}',[$controller,'show'])->whereNumber('reminder')->name('show');
                foreach (['history','revisions','occurrences'] as $kind) {
                    Route::get('{reminder}/'.$kind,[$controller,'history'])->whereNumber('reminder')->defaults('kind',$kind)->name($kind);
                }
                Route::middleware(['can:reminders.manage','throttle:reminder-writes'])->group(function () use ($controller): void {
                    Route::post('/',[$controller,'write'])->name('created');
                    Route::patch('{reminder}',[$controller,'write'])->whereNumber('reminder')->name('updated');
                    Route::post('{reminder}/cancel',[$controller,'write'])->whereNumber('reminder')->name('cancelled');
                });
            });
            Route::prefix('notifications')->name('notifications.')->middleware('can:notifications.view')->group(function (): void {
                $controller=\App\Http\Controllers\Api\V1\ReminderController::class;
                Route::get('summary',[$controller,'summary'])->name('summary');
                Route::get('/',[$controller,'notifications'])->name('index');
                Route::get('{notification}',[$controller,'notification'])->whereNumber('notification')->name('show');
                Route::get('{notification}/history',[$controller,'notificationHistory'])->whereNumber('notification')->name('history');
                foreach (['read'=>'read','unread'=>'unread','snooze'=>'snoozed'] as $path=>$action) {
                    Route::post('{notification}/'.$path,[$controller,'write'])->whereNumber('notification')
                        ->middleware(['can:notifications.update','throttle:reminder-writes'])->name($action);
                }
            });
            Route::prefix('hmo')->name('hmo.')->group(function (): void {
                $controller = \App\Http\Controllers\Api\V1\HmoController::class;
                Route::get('options', [$controller, 'options'])->middleware('can:hmo.catalog.view')->name('options');
                foreach (['providers'=>'provider','plans'=>'plan','imports'=>'tariff','remittances'=>'remittance'] as $plural=>$singular) {
                    $permission = $plural === 'remittances' ? 'hmo.view' : 'hmo.catalog.view';
                    Route::get($plural, [$controller, 'catalog'])->defaults('catalogKind', $plural)->middleware('can:'.$permission)->name($plural.'.index');
                    Route::get($plural.'/{hmoRecord}', [$controller, 'catalogShow'])->defaults('resourceKind', $singular)
                        ->whereNumber('hmoRecord')->middleware('can:'.$permission)->name($plural.'.show');
                }
                Route::post('imports/validate', [$controller, 'validateCsv'])->middleware(['can:hmo.catalog.view','can:hmo.tariffs.import','throttle:hmo-writes'])->name('csv.validate');
                Route::get('imports/{hmoRecord}/rows', [$controller, 'tariffRows'])->whereNumber('hmoRecord')->middleware('can:hmo.catalog.view')->name('tariffs.rows');
                Route::get('imports/{hmoRecord}/source', [$controller, 'csvSource'])->whereNumber('hmoRecord')->middleware('can:hmo.catalog.view')->name('tariffs.source');
                foreach (['providers'=>'provider-create','providers/{hmoRecord}/revisions'=>'provider-update','plans'=>'plan-create','plans/{hmoRecord}/revisions'=>'plan-update',
                    'imports'=>'tariff-import','imports/{hmoRecord}/review'=>'tariff-review','remittances'=>'remittance-create','remittances/{hmoRecord}/review'=>'remittance-review'] as $path=>$action) {
                    Route::post($path, [$controller, 'write'])->whereNumber('hmoRecord')
                        ->middleware(['can:'.\App\Services\HmoWorkflows::PERMISSIONS[$action],'throttle:hmo-writes'])->name($action);
                }
                Route::prefix('patients/{patient}')->where(['patient'=>'[1-9][0-9]{0,17}','hmoRecord'=>'[1-9][0-9]{0,17}'])
                    ->middleware(['can:patients.view','can:billing.view','can:hmo.view'])->group(function () use ($controller): void {
                        foreach (['coverages','preauthorizations','revocations','claims','submissions','decisions','shares','allocations','reviews'] as $kind) {
                            Route::get($kind, [$controller, 'records'])->defaults('kind', $kind)->name($kind.'.index');
                        }
                        Route::get('claims/{hmoRecord}', [$controller, 'claim'])->name('claim.show');
                        Route::get('reconciliation', [$controller, 'reconciliation'])->middleware('can:hmo.review')->name('reconciliation');
                        foreach (['coverages'=>'coverage-create','coverages/{hmoRecord}/revoke'=>'coverage-revoke','preauthorizations'=>'preauth-create',
                            'preauthorizations/{hmoRecord}/revoke'=>'preauth-revoke','invoices'=>'invoice','claims'=>'claim-submit','claims/{hmoRecord}/resubmit'=>'claim-resubmit',
                            'decisions'=>'decision-record','decisions/{hmoRecord}/review'=>'decision-review','allocations'=>'allocation-record','allocations/{hmoRecord}/review'=>'allocation-review'] as $path=>$action) {
                            Route::post($path, [$controller, 'write'])->middleware(['can:'.\App\Services\HmoWorkflows::PERMISSIONS[$action],'throttle:hmo-writes'])->name($action);
                        }
                    });
            });
            Route::prefix('billing')->name('billing.')->group(function (): void {
                $controller = \App\Http\Controllers\Api\V1\BillingController::class;
                Route::get('options', [$controller, 'options'])->middleware('can:billing.view')->name('options');
                Route::prefix('catalog')->middleware('can:billing.catalog.view')->group(function () use ($controller): void {
                    Route::get('services', [$controller, 'catalog'])->name('catalog.index');
                    Route::get('services/{billingService}/prices', [$controller, 'catalog'])->where('billingService', '[1-9][0-9]{0,17}')->name('catalog.prices');
                    Route::post('services', [$controller, 'write'])->middleware(['can:billing.catalog.manage','throttle:billing-writes'])->name('service');
                    Route::post('services/{billingService}/prices', [$controller, 'write'])->where('billingService', '[1-9][0-9]{0,17}')
                        ->middleware(['can:billing.catalog.manage','throttle:billing-writes'])->name('price');
                });
                Route::prefix('patients/{patient}')->where(['patient' => '[1-9][0-9]{0,17}', 'billingChange' => '[1-9][0-9]{0,17}', 'billingInvoice' => '[1-9][0-9]{0,17}'])
                    ->middleware(['can:patients.view','can:billing.view'])->group(function () use ($controller): void {
                        Route::get('/', [$controller, 'show'])->name('show');
                        Route::get('accommodation', [$controller, 'accommodation'])->middleware('can:encounters.view')->name('accommodation');
                        Route::get('history', [$controller, 'records'])->defaults('kind', 'history')->middleware('can:billing.review')->name('history');
                        Route::get('reconciliation', [$controller, 'reconciliation'])->middleware('can:billing.review')->name('reconciliation');
                        Route::get('invoices/{billingInvoice}', [$controller, 'invoice'])->name('invoice.show');
                        Route::get('sources/{sourceKind}', [$controller, 'sources'])->where('sourceKind', 'performed|investigation|dispense')->name('sources');
                        Route::get('{kind}', [$controller, 'records'])->where('kind', 'invoices|receipts|changes|performed-services|allocations|allocation-releases')->name('records');
                        foreach (['performed-services' => 'performed', 'invoices' => 'invoice', 'receipts' => 'receipt', 'allocations' => 'allocate',
                            'credits' => 'credit-request', 'refunds' => 'refund-request', 'changes/{billingChange}/approve' => 'approve', 'changes/{billingChange}/reject' => 'reject'] as $path => $action) {
                            Route::post($path, [$controller, 'write'])->middleware(['can:'.\App\Services\BillingWorkflows::PERMISSIONS[$action], 'throttle:billing-writes'])->name($action);
                        }
                    });
            });
            Route::prefix('medications')->name('medications.')->middleware(['can:patients.view', 'can:encounters.view', 'can:prescriptions.view', 'can:medications.view'])
                ->group(function (): void {
                    $controller = \App\Http\Controllers\Api\V1\MedicationController::class;
                    Route::get('options', [$controller, 'options'])->name('options');
                    Route::get('/', [$controller, 'index'])->name('index');
                    Route::post('authorizations', [$controller, 'write'])->middleware(['can:medications.authorize', 'throttle:stock-writes'])->name('authorize');
                    Route::prefix('{medication}')->where(['medication' => '[1-9][0-9]{0,17}'])->group(function () use ($controller): void {
                        Route::get('/', [$controller, 'show'])->name('show');
                        Route::get('history', [$controller, 'records'])->defaults('kind', 'history')->middleware('can:medications.review')->name('history');
                        Route::get('{kind}', [$controller, 'records'])->where('kind', 'dispenses|returns|administrations')->name('records');
                        foreach (\App\Services\MedicationFulfillment::PERMISSIONS as $action => $permission) {
                            if ($action !== 'authorize') {
                                Route::post($action, [$controller, 'write'])->middleware(['can:'.$permission, 'throttle:stock-writes'])->name($action);
                            }
                        }
                    });
                });
            Route::prefix('stock')->name('stock.')->group(function (): void {
                $controller = \App\Http\Controllers\Api\V1\StockController::class;
                Route::middleware('can:stock.catalog.view')->group(function () use ($controller): void {
                    Route::prefix('catalog/{catalog}')->where(['catalog' => 'units|drugs|packs|suppliers|stores|batches|prices', 'stockRecord' => '[1-9][0-9]{0,17}'])
                        ->name('catalog.')->group(function () use ($controller): void {
                            Route::get('/', [$controller, 'catalogIndex'])->name('index');
                            Route::get('{stockRecord}', [$controller, 'catalogShow'])->name('show');
                            Route::get('{stockRecord}/history', [$controller, 'catalogHistory'])->middleware('can:stock.review')->name('history');
                            Route::post('/', [$controller, 'write'])->middleware(['can:stock.catalog.manage','throttle:stock-writes'])->name('store');
                            Route::patch('{stockRecord}', [$controller, 'write'])->middleware(['can:stock.catalog.manage','throttle:stock-writes'])->name('update');
                        });
                    Route::get('stores/{stockStore}/access', [$controller, 'accesses'])->where('stockStore', '[1-9][0-9]{0,17}')
                        ->middleware('can:stock.access.manage')->name('accesses');
                    Route::get('stores/{stockStore}/access/{stockStaff}/history', [$controller, 'accessHistory'])
                        ->where(['stockStore' => '[1-9][0-9]{0,17}', 'stockStaff' => '[1-9][0-9]{0,17}'])
                        ->middleware(['can:stock.access.manage','can:stock.review'])->name('access-history');
                    Route::post('stores/{stockStore}/access/{stockStaff}', [$controller, 'write'])
                        ->where(['stockStore' => '[1-9][0-9]{0,17}', 'stockStaff' => '[1-9][0-9]{0,17}'])
                        ->middleware(['can:stock.access.manage','throttle:stock-writes'])->name('access');
                });
                Route::middleware('can:stock.view')->group(function () use ($controller): void {
                    Route::get('options', [$controller, 'options'])->name('options');
                    Route::get('my-stores', [$controller, 'myStores'])->name('my-stores');
                    Route::get('balances', [$controller, 'balances'])->name('balances');
                    Route::get('movements', [$controller, 'movements'])->name('movements');
                    Route::get('reconciliation', [$controller, 'reconciliation'])->middleware('can:stock.review')->name('reconciliation');
                    Route::get('operations', [$controller, 'index'])->name('index');
                    Route::post('operations', [$controller, 'write'])->middleware('throttle:stock-writes')->name('store');
                    Route::prefix('operations/{stockOperation}')->where(['stockOperation' => '[1-9][0-9]{0,17}'])->group(function () use ($controller): void {
                        Route::get('/', [$controller, 'show'])->name('show');
                        Route::get('history', [$controller, 'history'])->middleware('can:stock.review')->name('history');
                        Route::patch('/', [$controller, 'write'])->middleware('throttle:stock-writes')->name('update');
                        foreach (['submit','approve','reject','cancel'] as $action) {
                            Route::post($action, [$controller, 'write'])->middleware('throttle:stock-writes')->name($action);
                        }
                    });
                });
            });
            Route::middleware(['can:patients.view', 'can:encounters.view', 'can:investigations.view', 'can:investigations.results.view'])
                ->name('investigations.results.')->group(function (): void {
                    $controller = \App\Http\Controllers\Api\V1\InvestigationResultController::class;
                    Route::get('investigations/results/options', [$controller, 'options'])->name('options');
                    Route::get('investigations/results', [$controller, 'index'])->name('index');
                    Route::prefix('investigations/items/{investigationItem}/results')
                        ->where(['investigationItem' => '[1-9][0-9]{0,17}', 'investigationResult' => '[1-9][0-9]{0,17}', 'file' => '[1-9][0-9]{0,17}'])
                        ->group(function () use ($controller): void {
                            Route::get('/', [$controller, 'index'])->name('item-index');
                            Route::post('/', [$controller, 'write'])->middleware('throttle:clinical-writes')->name('store');
                            Route::get('{investigationResult}', [$controller, 'show'])->name('show');
                            Route::get('{investigationResult}/history', [$controller, 'history'])->middleware('can:investigations.results.review')->name('history');
                            Route::get('{investigationResult}/attachments/{file}/download', [$controller, 'download'])->name('download');
                            Route::patch('{investigationResult}', [$controller, 'write'])->middleware('throttle:clinical-writes')->name('update');
                            foreach (['submit', 'verify', 'release', 'amend', 'void'] as $action) {
                                Route::post('{investigationResult}/'.$action, [$controller, 'write'])->middleware('throttle:clinical-writes')->name($action);
                            }
                        });
                });
            Route::middleware(['can:patients.view', 'can:encounters.view', 'can:investigations.view'])->name('investigations.')->group(function (): void {
                $controller = \App\Http\Controllers\Api\V1\InvestigationRequestController::class;
                Route::get('investigations/options', [$controller, 'options'])->name('options');
                Route::get('investigations/orders', [$controller, 'index'])->name('orders.index');
                Route::get('investigations/queue', [$controller, 'queue'])->name('queue');
                Route::post('encounters/{encounter}/investigations', [$controller, 'write'])->where('encounter', '[1-9][0-9]{0,17}')
                    ->middleware(['can:investigations.request', 'throttle:clinical-writes'])->name('requests.store');
                Route::prefix('investigations/orders/{investigationOrder}')->where(['investigationOrder' => '[1-9][0-9]{0,17}', 'investigationItem' => '[1-9][0-9]{0,17}'])
                    ->group(function () use ($controller): void {
                        Route::get('/', [$controller, 'show'])->name('orders.show');
                        Route::get('history', [$controller, 'history'])->middleware('can:investigations.review')->name('orders.history');
                        Route::get('items/{investigationItem}', [$controller, 'item'])->name('orders.item');
                        foreach (['collect', 'process', 'cancel'] as $action) {
                            Route::post('items/{investigationItem}/'.$action, [$controller, 'write'])->middleware('throttle:clinical-writes')->name('requests.'.$action);
                        }
                    });
            });
            Route::prefix('investigations/catalog')->name('investigations.catalog.')->middleware('can:investigations.catalog.view')->group(function (): void {
                $controller = \App\Http\Controllers\Api\V1\InvestigationCatalogController::class;
                Route::get('options', [$controller, 'options'])->name('options');
                Route::get('/', [$controller, 'index'])->name('index');
                Route::get('{investigationTest}', [$controller, 'show'])->where('investigationTest', '[1-9][0-9]{0,17}')->name('show');
                Route::get('{investigationTest}/schemas', [$controller, 'schemas'])->where('investigationTest', '[1-9][0-9]{0,17}')->name('schemas');
                Route::get('{investigationTest}/schemas/{schema}', [$controller, 'schema'])
                    ->where(['investigationTest' => '[1-9][0-9]{0,17}', 'schema' => '[1-9][0-9]{0,17}'])->name('schema');
            });
            Route::middleware(['can:care.view','can:patients.view','can:encounters.view'])->group(function (): void {
                $controller = \App\Http\Controllers\Api\V1\CareProgramController::class;
                Route::get('care-programs/options', [$controller,'options'])->name('care-programs.options');
                Route::get('care-programs/forms', [$controller,'forms'])->name('care-programs.forms');
                Route::get('care-programs', [$controller,'index'])->name('care-programs.index');
                Route::post('encounters/{encounter}/care-programs', [$controller,'write'])->where('encounter','[1-9][0-9]{0,17}')
                    ->middleware(['can:care.manage','throttle:clinical-writes'])->name('care-programs.store');
                Route::prefix('care-programs/{careProgram}')->where(['careProgram'=>'[1-9][0-9]{0,17}'])->group(function () use ($controller): void {
                    Route::get('/', [$controller,'show'])->name('care-programs.show');
                    Route::get('births', [$controller,'births'])->name('care-programs.births');
                    Route::get('records', [$controller,'records'])->name('care-programs.records');
                    Route::post('complete', [$controller,'write'])->middleware(['can:care.manage','throttle:clinical-writes'])->name('care-programs.complete');
                    Route::post('births', [$controller,'write'])->middleware(['can:care.manage','throttle:clinical-writes'])->name('care-programs.birth');
                    Route::post('births/{birth}/link', [$controller,'write'])->where('birth','[1-9][0-9]{0,17}')
                        ->middleware(['can:care.link_birth','throttle:clinical-writes'])->name('care-programs.link');
                    Route::prefix('encounters/{encounter}/records')->where(['encounter'=>'[1-9][0-9]{0,17}','clinicalRecord'=>'[1-9][0-9]{0,17}'])
                        ->name('care.')->group(function () use ($controller): void {
                            Route::get('{clinicalRecord}',[$controller,'record'])->name('show');
                            Route::get('{clinicalRecord}/history',[$controller,'history'])->middleware('can:care.review')->name('history');
                            Route::middleware(['can:care.write','throttle:clinical-writes'])->group(function () use ($controller): void {
                                Route::post('/',[$controller,'clinicalWrite'])->name('store');
                                Route::patch('{clinicalRecord}',[$controller,'clinicalWrite'])->name('update');
                                foreach (['sign','amend','void'] as $action) {
                                    Route::post('{clinicalRecord}/'.$action,[$controller,'clinicalWrite'])
                                        ->middleware('can:care.'.($action === 'amend' ? 'amend' : ($action === 'void' ? 'void' : 'sign')))->name($action);
                                }
                            });
                        });
                });
            });
            Route::prefix('locations')->name('locations.')->middleware('can:locations.view')->group(function (): void {
                foreach (['wards', 'beds'] as $catalog) {
                    Route::get($catalog, [LocationController::class, $catalog])->name($catalog.'.index');
                    Route::get($catalog.'/{location}', [LocationController::class, 'show'])->where('location', '[1-9][0-9]{0,17}')->name($catalog.'.show');
                    Route::post($catalog, [LocationController::class, 'write'])->middleware(['can:locations.manage', 'throttle:admission-writes'])->name($catalog.'.store');
                    Route::patch($catalog.'/{location}', [LocationController::class, 'write'])->where('location', '[1-9][0-9]{0,17}')
                        ->middleware(['can:locations.manage', 'throttle:admission-writes'])->name($catalog.'.update');
                }
            });
            Route::middleware(['can:admissions.view', 'can:encounters.view', 'can:patients.view'])->name('admissions.')->group(function (): void {
                Route::get('admissions/options', [AdmissionController::class, 'options'])->name('options');
                Route::get('admissions', [AdmissionController::class, 'index'])->name('index');
                Route::post('encounters/{encounter}/admission', [AdmissionController::class, 'write'])->where('encounter', '[1-9][0-9]{0,17}')
                    ->middleware(['can:admissions.create', 'throttle:admission-writes'])->name('store');
                Route::prefix('admissions/{admission}')->where(['admission' => '[1-9][0-9]{0,17}'])->group(function (): void {
                    Route::get('accommodation', [AdmissionController::class, 'accommodation'])->name('accommodation');
                    Route::get('/', [AdmissionController::class, 'show'])->name('show');
                    Route::get('history', [AdmissionController::class, 'history'])->name('history');
                    Route::get('allocations', [AdmissionController::class, 'allocations'])->name('allocations');
                    foreach (\App\Services\AdmissionFlow::ACTIONS as $action => [$permission]) {
                        if ($action !== 'store') {
                            Route::post($action, [AdmissionController::class, 'write'])->middleware(['can:'.$permission, 'throttle:admission-writes'])->name($action);
                        }
                    }
                });
            });
            Route::get('patients/{patient}/history', PatientHistoryController::class)->where('patient', '[1-9][0-9]{0,17}')
                ->middleware(['can:patients.view', 'can:encounters.view', 'can:clinical.history.view'])->name('patients.history');
            Route::middleware(['can:prescriptions.view', 'can:encounters.view', 'can:patients.view'])->name('prescriptions.')->group(function (): void {
                Route::get('prescriptions/options', [PrescriptionController::class, 'options'])->name('options');
                Route::prefix('encounters/{encounter}/prescriptions')->where(['encounter' => '[1-9][0-9]{0,17}', 'clinicalRecord' => '[1-9][0-9]{0,17}'])
                    ->group(function (): void {
                        Route::get('/', [PrescriptionController::class, 'index'])->name('index');
                        Route::get('{clinicalRecord}', [PrescriptionController::class, 'show'])->name('show');
                        Route::get('{clinicalRecord}/history', [PrescriptionController::class, 'history'])->middleware('can:prescriptions.review')->name('history');
                        Route::middleware(['can:prescriptions.write', 'throttle:clinical-writes'])->group(function (): void {
                            Route::post('/', [PrescriptionController::class, 'write'])->name('store');
                            Route::patch('{clinicalRecord}', [PrescriptionController::class, 'write'])->name('update');
                            Route::post('{clinicalRecord}/sign', [PrescriptionController::class, 'write'])->middleware('can:prescriptions.sign')->name('sign');
                            foreach (['amend', 'stop', 'complete'] as $action) {
                                Route::post('{clinicalRecord}/'.$action, [PrescriptionController::class, 'write'])
                                    ->middleware(['can:prescriptions.amend', 'can:prescriptions.sign'])->name($action);
                            }
                            Route::post('{clinicalRecord}/void', [PrescriptionController::class, 'write'])->middleware('can:prescriptions.void')->name('void');
                        });
                    });
            });
            Route::middleware(['can:clinical.view', 'can:encounters.view', 'can:patients.view'])->name('clinical.')->group(function (): void {
                Route::get('clinical/options', [ClinicalController::class, 'options'])->name('options');
                Route::get('clinical/templates', [ClinicalController::class, 'templates'])->name('templates');
                Route::prefix('encounters/{encounter}/clinical')->where(['encounter' => '[1-9][0-9]{0,17}', 'clinicalRecord' => '[1-9][0-9]{0,17}'])
                    ->group(function (): void {
                        Route::get('/', [ClinicalController::class, 'index'])->name('index');
                        Route::get('{clinicalRecord}', [ClinicalController::class, 'show'])->name('show');
                        Route::get('{clinicalRecord}/history', [ClinicalController::class, 'history'])->middleware('can:clinical.review')->name('history');
                        Route::middleware(['can:clinical.write', 'throttle:clinical-writes'])->group(function (): void {
                            Route::post('/', [ClinicalController::class, 'write'])->name('store');
                            Route::patch('{clinicalRecord}', [ClinicalController::class, 'write'])->name('update');
                            Route::post('{clinicalRecord}/sign', [ClinicalController::class, 'write'])->middleware('can:clinical.sign')->name('sign');
                            Route::post('{clinicalRecord}/amend', [ClinicalController::class, 'write'])
                                ->middleware(['can:clinical.amend', 'can:clinical.sign'])->name('amend');
                            Route::post('{clinicalRecord}/void', [ClinicalController::class, 'write'])->middleware('can:clinical.void')->name('void');
                        });
                    });
            });
            Route::middleware(['can:encounters.view', 'can:patients.view'])->group(function (): void {
                Route::get('queues', [EncounterController::class, 'index'])->name('queues.index');
                Route::prefix('encounters')->name('encounters.')->group(function (): void {
                    Route::get('options', [EncounterController::class, 'options'])->name('options');
                    Route::get('clinicians', [AppointmentController::class, 'clinicians'])
                        ->middleware(['can:encounters.assign', 'can:encounters.view_any'])->name('clinicians');
                    Route::get('/', [EncounterController::class, 'index'])->name('index');
                    Route::get('{encounter}', [EncounterController::class, 'show'])->where('encounter', '[1-9][0-9]{0,17}')->name('show');
                    Route::get('{encounter}/history', [EncounterController::class, 'history'])->where('encounter', '[1-9][0-9]{0,17}')->name('history');
                    Route::get('{encounter}/assignments', [EncounterController::class, 'assignments'])->where('encounter', '[1-9][0-9]{0,17}')->name('assignments');
                    Route::middleware('throttle:encounter-writes')->group(function (): void {
                        Route::post('/', [EncounterController::class, 'write'])->middleware(['can:encounters.arrive', 'can:encounters.view_any'])->name('store');
                        foreach (['assign', 'reassign', 'accept', 'complete', 'cancel'] as $action) {
                            $permission = match ($action) { 'assign', 'reassign' => 'encounters.assign', 'accept', 'complete' => 'encounters.progress', 'cancel' => 'encounters.cancel' };
                            Route::post('{encounter}/'.$action, [EncounterController::class, 'write'])->where('encounter', '[1-9][0-9]{0,17}')
                                ->middleware('can:'.$permission)->name($action);
                        }
                    });
                });
                Route::post('appointments/{appointment}/arrive', [EncounterController::class, 'write'])->where('appointment', '[1-9][0-9]{0,17}')
                    ->middleware(['can:appointments.view', 'can:encounters.arrive', 'can:encounters.view_any', 'throttle:encounter-writes'])->name('appointments.arrive');
            });
            Route::prefix('appointments')->name('appointments.')->middleware('can:appointments.view')->group(function (): void {
                Route::get('options', [AppointmentController::class, 'options'])->name('options');
                Route::get('clinicians', [AppointmentController::class, 'clinicians'])->name('clinicians');
                Route::get('/', [AppointmentController::class, 'index'])->name('index');
                Route::get('{appointment}', [AppointmentController::class, 'show'])->where('appointment', '[1-9][0-9]{0,17}')->name('show');
                Route::get('{appointment}/history', [AppointmentController::class, 'history'])->where('appointment', '[1-9][0-9]{0,17}')->name('history');
                Route::middleware('throttle:appointment-writes')->group(function (): void {
                    Route::post('/', [AppointmentController::class, 'store'])->middleware('can:appointments.create')->name('store');
                    Route::patch('{appointment}', [AppointmentController::class, 'update'])->where('appointment', '[1-9][0-9]{0,17}')
                        ->middleware('can:appointments.update')->name('update');
                    foreach (['confirm', 'cancel', 'no-show'] as $action) {
                        Route::post('{appointment}/'.$action, [AppointmentController::class, 'action'])->where('appointment', '[1-9][0-9]{0,17}')
                            ->middleware('can:appointments.update')->name($action);
                    }
                });
            });
            Route::middleware('can:patients.view')->group(function (): void {
                Route::prefix('patients/{patient}')->where(['patient' => '[1-9][0-9]{0,17}', 'flag' => '[1-9][0-9]{0,17}', 'mergeRequest' => '[1-9][0-9]{0,17}'])
                    ->name('patients.records.')->group(function (): void {
                        Route::get('flags', [PatientRecordController::class, 'flags'])->middleware('can:patients.flags.view')->name('flags.index');
                        Route::get('card-history', [PatientRecordController::class, 'cards'])->middleware('can:patients.cards.manage')->name('cards.index');
                        Route::get('access-state', [PatientRecordController::class, 'access'])->middleware('can:patients.access.manage')->name('access.show');
                        Route::get('merge-requests', [PatientRecordController::class, 'merges'])->can('reviewMerges', Patient::class)->name('merges.index');
                        Route::middleware('throttle:patient-writes')->group(function (): void {
                            Route::post('flags', [PatientRecordController::class, 'write'])->middleware(['can:patients.flags.view', 'can:patients.flags.manage'])->name('flags.store');
                            Route::post('flags/{flag}/resolve', [PatientRecordController::class, 'write'])->middleware(['can:patients.flags.view', 'can:patients.flags.manage'])->name('flags.resolve');
                            Route::post('access/restrict', [PatientRecordController::class, 'write'])->middleware('can:patients.access.manage')->name('access.restrict');
                            Route::post('access/restore', [PatientRecordController::class, 'write'])->middleware('can:patients.access.manage')->name('access.restore');
                            Route::post('card', [PatientRecordController::class, 'write'])->middleware('can:patients.cards.manage')->name('cards.change');
                            Route::post('merge-requests', [PatientRecordController::class, 'write'])->middleware('can:patients.merges.request')->name('merges.request');
                            Route::post('merge-requests/{mergeRequest}/approve', [PatientRecordController::class, 'write'])->middleware('can:patients.merges.approve')->name('merges.approve');
                            Route::post('merge-requests/{mergeRequest}/reject', [PatientRecordController::class, 'write'])->middleware('can:patients.merges.approve')->name('merges.reject');
                            Route::post('merge-requests/{mergeRequest}/cancel', [PatientRecordController::class, 'write'])->middleware('can:patients.merges.request')->name('merges.cancel');
                        });
                    });
                Route::get('patients/options', [PatientController::class, 'options'])->name('patients.options');
                Route::post('patients/duplicate-check', [PatientController::class, 'duplicates'])->name('patients.duplicates');
                Route::get('patients', [PatientController::class, 'index'])->name('patients.index');
                Route::get('patients/{patient}', [PatientController::class, 'show'])
                    ->where('patient', '[1-9][0-9]{0,17}')->name('patients.show');
                Route::post('patients', [PatientController::class, 'store'])
                    ->middleware(['can:patients.create', 'throttle:patient-writes'])->name('patients.store');
                Route::patch('patients/{patient}', [PatientController::class, 'update'])
                    ->where('patient', '[1-9][0-9]{0,17}')
                    ->middleware(['can:patients.update', 'throttle:patient-writes'])->name('patients.update');
            });
            Route::middleware('can:files.view_own')->group(function (): void {
                Route::get('files', [ProtectedFileController::class, 'index'])->name('files.index');
                Route::post('files', [ProtectedFileController::class, 'store'])
                    ->middleware(['can:files.upload', 'throttle:protected-files-upload'])->name('files.store');
                Route::get('files/{file}', [ProtectedFileController::class, 'show'])
                    ->where('file', '[1-9][0-9]{0,17}')->name('files.show');
                Route::get('files/{file}/download', [ProtectedFileController::class, 'download'])
                    ->where('file', '[1-9][0-9]{0,17}')->name('files.download');
            });
            Route::get('audit-events', AuditHistoryController::class)
                ->middleware('can:audit.view')->name('audit-events.index');
            Route::get('auth/me', [StaffSessionController::class, 'show'])->name('auth.me');
            Route::post('auth/logout', [StaffSessionController::class, 'destroy'])->name('auth.logout');
            Route::get('departments', [DepartmentController::class, 'index'])
                ->can('viewAny', Department::class)->name('departments.index');
            Route::get('departments/{department}', [DepartmentController::class, 'show'])
                ->can('view', 'department')->name('departments.show');
            Route::get('staff', [StaffDirectoryController::class, 'index'])
                ->can('viewAny', User::class)->name('staff.index');
            Route::get('staff/{user}', [StaffDirectoryController::class, 'show'])
                ->can('view', 'user')->name('staff.show');
            Route::get('access-control', AccessControlController::class)
                ->middleware('can:authorization.view')->name('access-control.index');
            Route::get('staff-management', [StaffManagementController::class, 'index'])
                ->can('manageAny', User::class)->name('staff-management.index');
            Route::get('staff-management/options', [StaffManagementController::class, 'options'])
                ->can('manageAny', User::class)->name('staff-management.options');
            Route::get('staff-management/{user}', [StaffManagementController::class, 'show'])
                ->can('manage', 'user')->name('staff-management.show');
            Route::middleware('throttle:staff-management')->group(function (): void {
                Route::post('staff-management', [StaffManagementController::class, 'store'])
                    ->can('create', User::class)->name('staff-management.store');
                Route::patch('staff-management/{user}', [StaffManagementController::class, 'update'])
                    ->can('update', 'user')->name('staff-management.update');
                Route::post('staff-management/{user}/password-reset', [StaffManagementController::class, 'issueReset'])
                    ->can('issuePasswordReset', 'user')->name('staff-management.password-reset');
            });
        });
    });
});
