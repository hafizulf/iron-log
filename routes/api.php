<?php

use App\Http\Controllers\Api\AuditLogController;

Route::prefix('audit-logs')->group(function () {
    Route::post('/', [AuditLogController::class, 'store']);
    Route::get('/', [AuditLogController::class, 'index']);
    Route::get('/{id}/verify/', [AuditLogController::class, 'verify']);
});
