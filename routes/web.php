<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use App\Http\Controllers\XMLController;
use App\Http\Controllers\XMLController2;
use App\Http\Controllers\XMLBatchController; // Nuevo controlador para batches
use App\Http\Controllers\PagosController;


Route::get('/', function () {
    return Inertia::render('Welcome');
})->name('home');

Route::get('dashboard', function () {
    return Inertia::render('Dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';


Route::prefix('xml')->group(function () {
    Route::get('/files', [XMLController::class, 'index']); // Listar archivos
    Route::get('/read/{filename}', [XMLController::class, 'readFile']); // Leer específico
    Route::post('/read-multiple', [XMLController::class, 'readMultiple']); // Leer múltiples
    Route::post('/upload', [XMLController::class, 'upload']); // Subir archivo
    Route::get('/search', [XMLController::class, 'search']); // Buscar contenido
});

Route::prefix('cfdi')->group(function () {
    Route::get('/extract', [XMLController::class, 'extractCfdiInfo']);
    Route::get('/uuid/{uuid}', [XMLController::class, 'findByUuid']);
    Route::get('/rfc/{rfc}', [XMLController::class, 'findByRfc']);
    Route::get('/summary', [XMLController::class, 'summary']);
    Route::get('/verify/{filename}', [XMLController::class, 'verifyCfdi']);
    Route::get('/export/csv', [XMLController::class, 'exportToCsv']);
    Route::get('/files', [XMLController::class, 'index']);
});

Route::prefix('cfdiextract')->group(function () {
    Route::get('/extract', [XMLController2::class, 'extractCfdiInfo']);
    Route::get('/uuid/{uuid}', [XMLController2::class, 'findByUuid']);
    Route::get('/rfc/{rfc}', [XMLController2::class, 'findByRfc']);
    Route::get('/summary', [XMLController2::class, 'summary']);
    Route::get('/verify/{filename}', [XMLController2::class, 'verifyCfdi']);
    Route::get('/export/csv', [XMLController2::class, 'exportToCsv']);
    Route::get('/files', [XMLController2::class, 'index']);
});


Route::prefix('cfdi/batch')->group(function () {

    // 1. Iniciar proceso de batch y obtener total de bloques
    Route::get('/init', [XMLBatchController::class, 'initBatch']);

    // 2. Descargar un batch específico (por índice)
    Route::get('/download/{batchIndex}', [XMLBatchController::class, 'downloadBatch'])
        ->where('batchIndex', '[0-9]+');

    // 3. Descargar todos los batches como ZIP
    Route::get('/download-all', [XMLBatchController::class, 'downloadAllBatches']);

    // 4. Estado del procesamiento
    Route::get('/status/{batchId}', [XMLBatchController::class, 'getStatus']);

    // 5. Descargar batch por rango de fechas (alternativa)
    Route::get('/by-date', [XMLBatchController::class, 'downloadByDateRange']);

    // 6. Descargar batch por carpeta específica
    Route::get('/by-folder/{folder}', [XMLBatchController::class, 'downloadByFolder']);
});

// ============================================
// RUTA ALTERNATIVA SIMPLE: Streaming directo
// ============================================

Route::get('/cfdi/stream-csv', [XMLBatchController::class, 'streamLargeCsv']);

// ============================================
// PAGOS: Conversor TXT (posiciones fijas) → Excel
// ============================================
Route::prefix('pagos')->name('pagos.')->group(function () {
    Route::get('/',              [PagosController::class, 'index'])->name('index');
    Route::post('/convertir',    [PagosController::class, 'convertir'])->name('convertir');
    Route::post('/descargar',    [PagosController::class, 'descargar'])->name('descargar');
});
