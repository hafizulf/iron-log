<?php

use App\Http\Controllers\Api\AuditLogController;

Route::post('/audit-logs', [AuditLogController::class, 'store']);

